<?php

namespace App\Console\Commands;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\Client;
use App\Services\AttendanceEmployeeGuard;
use Illuminate\Console\Command;

/**
 * Libera los marcajes `retenido` de un cliente cuya sincronización estuvo en
 * pausa (config attendance.sync_hold_clients / ATTENDANCE_SYNC_HOLD_CLIENTS).
 *
 * Se niega mientras el cliente siga en la lista: primero se quita de
 * ATTENDANCE_SYNC_HOLD_CLIENTS y se corre `php artisan config:cache`.
 * Despacha del más viejo al más nuevo (orderBy occurred_at) con 2 s entre
 * jobs, igual que la ingesta de buffers offline, y cada registro pasa por el
 * guardarraíl H01 antes de volver a la cola.
 */
class ReleaseHeldAttendanceLogs extends Command
{
    protected $signature = 'attendance:liberar-retenidos
                            {--client= : client_id cuyos marcajes retenidos se liberan (obligatorio)}
                            {--dry-run : Muestra qué haría, sin escribir ni despachar}';

    protected $description = 'Libera los marcajes retenidos de un cliente y los despacha a Factorial en orden';

    public function handle(): int
    {
        $raw = (string) $this->option('client');

        if (!ctype_digit($raw) || (int) $raw <= 0 || !Client::whereKey((int) $raw)->exists()) {
            $this->error('--client es obligatorio y debe ser el id de un cliente existente.');
            return self::FAILURE;
        }

        $clientId = (int) $raw;
        $dryRun   = (bool) $this->option('dry-run');

        if (SyncAttendanceToFactorial::clientOnHold($clientId)) {
            $this->error("El cliente {$clientId} sigue en ATTENDANCE_SYNC_HOLD_CLIENTS. Quítalo del .env, corre `php artisan config:cache` y reintenta.");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN: no se escribe nada ni se despacha ningún job.');
        }

        $logs = AttendanceLog::query()
            ->where('client_id', $clientId)
            ->where('sync_status', 'retenido')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'client_id', 'factorial_employee_id', 'employee_code', 'check_type', 'occurred_at']);

        $delay    = 0;
        $released = 0;
        $blocked  = 0;

        foreach ($logs as $log) {
            if (!AttendanceEmployeeGuard::canDispatch($log, 'attendance:liberar-retenidos')) {
                $blocked++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] log {$log->id} ({$log->check_type} {$log->occurred_at->format('Y-m-d H:i:s')}, PIN {$log->employee_code}) → cola +{$delay}s");
                $delay += 2;
                $released++;
                continue;
            }

            AttendanceLog::where('id', $log->id)->where('sync_status', 'retenido')->update(['sync_status' => 'resolved']);
            SyncAttendanceToFactorial::dispatch($log->id)->delay(now()->addSeconds($delay));
            $delay += 2;
            $released++;
        }

        $this->info("Cliente {$clientId}: " . ($dryRun ? 'se liberarían' : 'liberados y despachados') . " {$released} de {$logs->count()} marcajes retenidos.");

        if ($blocked > 0) {
            $this->error("Bloqueados por guardarraíl H01 (empleado ajeno al cliente): {$blocked}. Siguen `retenido`; revisa el log critical.");
        }

        return self::SUCCESS;
    }
}
