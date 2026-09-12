<?php

namespace App\Console\Commands;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricUserSync;
use App\Services\AttendanceEmployeeGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve attendance logs pendientes usando los mapeos PIN → empleado.
 *
 * Incidencia H01: hasta el 2026-09-12 el mapa PIN → empleado se construía
 * GLOBAL (un solo `pluck` sobre todos los `biometric_user_syncs`) y se aplicaba
 * a los logs `pending` de cualquier empresa. Los PIN se repiten entre clientes
 * (1, 2, 3, 5, 9, 45…), así que un PIN sin mapeo en su propia empresa tomaba el
 * empleado de otra y le creaba turnos en SU Factorial.
 *
 * Ahora el mapa se construye POR CLIENTE (y, cuando el log viene de un
 * dispositivo con proveedor, se prefiere el mapeo de ese proveedor, igual que
 * `attendance:resolve`). Un PIN sin mapeo en su propio cliente se queda
 * `pending`: nunca se toma el empleado de otra empresa.
 */
class ResolvePendingAttendanceLogs extends Command
{
    protected $signature = 'attendance:resolve-pending
                            {--client= : Limitar a un client_id}
                            {--dry-run : Muestra qué haría, sin escribir ni despachar}';

    protected $description = 'Resuelve attendance logs pendientes usando mapeos del MISMO cliente y despacha sync jobs';

    /** Mapas por cliente, memoizados: client_id => ['por_proveedor' => [...], 'del_cliente' => [...]] */
    private array $mapCache = [];

    public function handle(): int
    {
        // RevisiÃ³n H01, condiciÃ³n A2: un --client mal escrito (Â«abcÂ», Â«0Â», un id
        // que no existe) antes se convertÃ­a en Â«todos los clientesÂ». Ahora aborta.
        $clientOpt    = $this->option('client');
        $clientFilter = null;
        if ($clientOpt !== null && $clientOpt !== '') {
            if (!ctype_digit((string) $clientOpt) || (int) $clientOpt === 0) {
                $this->error("--client debe ser el id numÃ©rico de un cliente; recibÃ­ Â«{$clientOpt}Â». No se hizo nada.");
                return self::INVALID;
            }
            $clientFilter = (int) $clientOpt;
            if (!\App\Models\Client::whereKey($clientFilter)->exists()) {
                $this->error("No existe el cliente {$clientFilter}. No se hizo nada.");
                return self::INVALID;
            }
        }
        $dryRun       = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY-RUN: no se escribe nada ni se despacha ningún job.');
        }

        $localized = $this->markLocalOnlyLogs($clientFilter, $dryRun);

        // Pendientes sin empleado asignado. Se recorren en orden global por
        // occurred_at (los buffers offline deben viajar del más viejo al más
        // nuevo) y el mapa se resuelve por cliente dentro del bucle.
        $logs = AttendanceLog::query()
            ->whereNull('factorial_employee_id')
            ->where('sync_status', 'pending')
            ->when($clientFilter, fn ($q) => $q->where('client_id', $clientFilter))
            ->with('biometricSource:id,biometric_provider_id')
            ->orderBy('occurred_at')
            ->get(['id', 'client_id', 'employee_code', 'biometric_source_id']);

        $delay    = 0;
        $resolved = 0;
        $sinMapeo = 0;
        $blocked  = 0;

        foreach ($logs as $log) {
            $empId = $this->employeeForLog($log);

            if (!$empId) {
                $sinMapeo++;
                continue;
            }

            // Cinturón y tirantes: el mapa ya es del cliente del log, pero el
            // mapeo mismo pudo grabarse mal. Se verifica contra el modelo de
            // datos antes de tocar nómina.
            $candidate = (object) [
                'id'                    => $log->id,
                'client_id'             => $log->client_id,
                'factorial_employee_id' => $empId,
            ];

            if (!AttendanceEmployeeGuard::canDispatch($candidate, 'attendance:resolve-pending')) {
                $blocked++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] log {$log->id} (cliente {$log->client_id}, PIN {$log->employee_code}) → empleado {$empId}");
                $resolved++;
                continue;
            }

            AttendanceLog::where('id', $log->id)->update([
                'factorial_employee_id' => $empId,
                'sync_status'           => 'resolved',
            ]);

            SyncAttendanceToFactorial::dispatch($log->id)->delay(now()->addSeconds($delay));
            $delay += 2;
            $resolved++;
        }

        $requeued = $this->requeueStaleResolved($clientFilter, $dryRun, $delay, $blocked);

        $this->info("Resueltos y despachados: {$resolved} | Sin mapeo en su cliente (siguen pending): {$sinMapeo} | Marcados local: {$localized} | Re-despachados huérfanos: {$requeued}");

        if ($blocked > 0) {
            $this->error("Bloqueados por guardarraíl H01 (empleado ajeno al cliente): {$blocked}. Revisa el log critical.");
        }

        return self::SUCCESS;
    }

    /**
     * Empleados sólo locales (sin Factorial): sus logs pasan a `local` y no se
     * despachan. Acotado por cliente — antes marcaba `local` los logs de
     * cualquier empresa que compartiera el PIN.
     */
    private function markLocalOnlyLogs(?int $clientFilter, bool $dryRun): int
    {
        $total = 0;

        foreach ($this->clientsWithPendingLogs($clientFilter) as $clientId) {
            $localPins = BiometricUserSync::query()
                ->where('client_id', $clientId)
                ->whereNull('factorial_employee_id')
                ->whereNotNull('local_name')
                ->pluck('external_employee_code');

            if ($localPins->isEmpty()) {
                continue;
            }

            $query = AttendanceLog::query()
                ->where('client_id', $clientId)
                ->whereIn('employee_code', $localPins)
                ->where('sync_status', 'pending');

            $total += $dryRun ? $query->count() : $query->update(['sync_status' => 'local']);
        }

        return $total;
    }

    /** @return array<int, int> */
    private function clientsWithPendingLogs(?int $clientFilter): array
    {
        return AttendanceLog::query()
            ->where('sync_status', 'pending')
            ->when($clientFilter, fn ($q) => $q->where('client_id', $clientFilter))
            ->distinct()
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Mapeo PIN → empleado para un log, buscando SÓLO entre los mapeos de su
     * propio cliente. Si el dispositivo tiene proveedor, se prefiere el mapeo
     * de ese proveedor (patrón de `attendance:resolve`); si no hay, se cae al
     * mapa del cliente, y si ese PIN es ambiguo dentro del cliente (dos
     * proveedores lo mapean a empleados distintos) no se resuelve.
     */
    private function employeeForLog(AttendanceLog $log): ?int
    {
        $maps       = $this->mapsForClient((int) $log->client_id);
        $providerId = $log->biometricSource?->biometric_provider_id;

        if ($providerId && isset($maps['por_proveedor'][$providerId][$log->employee_code])) {
            return $maps['por_proveedor'][$providerId][$log->employee_code];
        }

        $wide = $maps['del_cliente'][$log->employee_code] ?? null;

        if ($wide === 'ambiguo') {
            Log::warning('attendance:resolve-pending — PIN ambiguo dentro del mismo cliente, se queda pending', [
                'attendance_log_id' => $log->id,
                'client_id'         => $log->client_id,
                'employee_code'     => $log->employee_code,
            ]);

            return null;
        }

        return $wide;
    }

    /** @return array{por_proveedor: array<int|string, array<string, int>>, del_cliente: array<string, int|string>} */
    private function mapsForClient(int $clientId): array
    {
        if (isset($this->mapCache[$clientId])) {
            return $this->mapCache[$clientId];
        }

        $rows = BiometricUserSync::query()
            ->where('client_id', $clientId)
            ->whereNotNull('factorial_employee_id')
            ->get(['external_employee_code', 'factorial_employee_id', 'biometric_provider_id']);

        $porProveedor = [];
        $delCliente   = [];

        foreach ($rows as $row) {
            $code  = (string) $row->external_employee_code;
            $empId = (int) $row->factorial_employee_id;

            if ($row->biometric_provider_id !== null) {
                $porProveedor[$row->biometric_provider_id][$code] = $empId;
            }

            if (array_key_exists($code, $delCliente) && $delCliente[$code] !== $empId) {
                $delCliente[$code] = 'ambiguo';
                continue;
            }

            $delCliente[$code] = $empId;
        }

        return $this->mapCache[$clientId] = [
            'por_proveedor' => $porProveedor,
            'del_cliente'   => $delCliente,
        ];
    }

    /**
     * Resueltos huérfanos (job perdido por worker caído) → re-despachar.
     *
     * No usa el mapa, pero sí puede re-despachar una asignación cruzada que ya
     * estuviera grabada en la tabla, así que pasa por el mismo guardarraíl.
     */
    private function requeueStaleResolved(?int $clientFilter, bool $dryRun, int $delay, int &$blocked): int
    {
        $stale = AttendanceLog::query()
            ->where('sync_status', 'resolved')
            ->whereNotNull('factorial_employee_id')
            ->where('updated_at', '<', now()->subMinutes(5))
            ->when($clientFilter, fn ($q) => $q->where('client_id', $clientFilter))
            ->orderBy('occurred_at')
            ->get(['id', 'client_id', 'factorial_employee_id']);

        $requeued = 0;

        foreach ($stale as $log) {
            if (!AttendanceEmployeeGuard::canDispatch($log, 'attendance:resolve-pending (huérfanos)')) {
                $blocked++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] re-despacharía log huérfano {$log->id}");
                $requeued++;
                continue;
            }

            SyncAttendanceToFactorial::dispatch($log->id)->delay(now()->addSeconds($delay));
            $delay += 2;
            $requeued++;
        }

        return $requeued;
    }
}
