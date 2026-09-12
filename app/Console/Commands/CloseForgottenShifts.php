<?php

namespace App\Console\Commands;

use App\Jobs\CloseForgottenShiftJob;
use App\Jobs\SyncAttendanceToFactorial;
use App\Models\ClientAttendanceConfig;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\FactorialService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CloseForgottenShifts extends Command
{
    protected $signature   = 'attendance:close-forgotten-shifts';
    protected $description = 'Cierra turnos abiertos más allá de lo esperado (horas asignadas + margen) para clientes con auto_close_forgotten_shifts activo';

    // Margen de espera antes de intervenir. La hora GRABADA como salida no
    // incluye este margen — solo decide cuándo actuamos (ver CloseForgottenShiftJob).
    private const MARGIN_MINUTES = 4 * 60;

    // Usado cuando Factorial no tiene horas planificadas para ese día
    // (sin contrato con horario, o expected_minutes = 0 — ver nota en handle()).
    private const DEFAULT_SHIFT_MINUTES = 8 * 60;

    // Empleados por llamada a estimated_times para UNA fecha (≤ 100 filas =
    // una página). FactorialService además trocea a 80 por el límite de URI.
    private const ESTIMATES_BATCH = 100;

    public function handle(): int
    {
        $clientConfigs = ClientAttendanceConfig::where('auto_close_forgotten_shifts', true)
            ->get()
            ->keyBy('client_id');

        if ($clientConfigs->isEmpty()) {
            $this->info('Sin clientes con auto_close_forgotten_shifts activo.');
            return self::SUCCESS;
        }

        $connections = FactorialConnection::whereIn('client_id', $clientConfigs->keys())
            ->whereNotNull('access_token')
            ->get();

        $totalClosed = 0;

        foreach ($connections as $connection) {
            $config = $clientConfigs[$connection->client_id] ?? null;
            if (!$config) continue;

            // Cliente retenido: el cierre también escribe en Factorial.
            if (SyncAttendanceToFactorial::clientOnHold($connection->client_id)) {
                $this->warn("Conexión #{$connection->id}: cliente {$connection->client_id} retenido (ATTENDANCE_SYNC_HOLD_CLIENTS), se salta.");
                continue;
            }

            $totalClosed += $this->processConnection($connection, $config);
        }

        $this->info("Turnos cerrados: {$totalClosed}");
        return self::SUCCESS;
    }

    private function processConnection(FactorialConnection $connection, ClientAttendanceConfig $config): int
    {
        $employees = FactorialEmployee::where('factorial_connection_id', $connection->id)
            ->where('active', true)
            ->get(['id', 'factorial_id', 'full_name']);

        if ($employees->isEmpty()) return 0;

        $service = new FactorialService($connection);
        $employeesByFactorialId = $employees->keyBy('factorial_id');

        try {
            $open = $service->fetchOpenShifts($employees->pluck('factorial_id')->all());
        } catch (\Throwable $e) {
            Log::error('attendance:close-forgotten-shifts: fallo al leer open_shifts', [
                'connection_id' => $connection->id,
                'error'         => $e->getMessage(),
            ]);
            $this->error("Conexión #{$connection->id}: error al leer open_shifts — {$e->getMessage()}");
            return 0;
        }

        // Lote de open_shifts truncado (has_next_page=true): para esos
        // empleados no sabemos qué turnos faltan — no se cierra a ninguno en
        // esta corrida; se reintenta a la hora siguiente.
        $skipEmployees = array_fill_keys(array_map('intval', $open['truncated_ids']), true);

        $openShifts = collect($open['data'])->filter(function ($shift) use ($employeesByFactorialId, $skipEmployees) {
            $id = (int) ($shift['employee_id'] ?? 0);

            return isset($employeesByFactorialId[$id]) && !isset($skipEmployees[$id]) && !empty($shift['date']);
        })->values();

        if ($openShifts->isEmpty()) return 0;

        // R01: estimated_times SÓLO de quien tiene turno abierto y agrupado
        // por la fecha de su turno. Una fecha × ≤ 80 empleados (lote de
        // FactorialService) = ≤ 80 filas = una sola página. Antes se pedía
        // para todos los activos en el rango min..max de todos los turnos
        // abiertos, lo que garantizaba el truncado y los cierres falsos de 8 h.
        $estimatedByKey = [];
        $undecided      = []; // "empleado_fecha" cuya estimación no se pudo leer completa

        foreach ($openShifts->groupBy('date') as $date => $shiftsOfDate) {
            $ids = $shiftsOfDate->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

            foreach (array_chunk($ids, self::ESTIMATES_BATCH) as $batch) {
                try {
                    $estimates = $service->fetchEstimatedTimes($batch, $date, $date);
                } catch (\Throwable $e) {
                    Log::error('attendance:close-forgotten-shifts: fallo al leer estimated_times, no se cierra ese lote', [
                        'connection_id' => $connection->id,
                        'date'          => $date,
                        'empleados'     => count($batch),
                        'error'         => $e->getMessage(),
                    ]);
                    $this->error("Conexión #{$connection->id}: error al leer estimated_times del {$date} — {$e->getMessage()}");
                    foreach ($batch as $id) $undecided[$id . '_' . $date] = true;
                    continue;
                }

                foreach ($estimates['data'] as $row) {
                    $estimatedByKey[($row['employee_id'] ?? '') . '_' . ($row['date'] ?? '')] = $row;
                }

                foreach ($estimates['truncated_ids'] as $id) {
                    $undecided[(int) $id . '_' . $date] = true;
                }
            }
        }

        if ($skipEmployees !== [] || $undecided !== []) {
            Log::warning('attendance:close-forgotten-shifts: respuesta incompleta de Factorial, esos empleados no se cierran en esta corrida', [
                'connection_id'                 => $connection->id,
                'empleados_open_shifts_trunc'   => count($skipEmployees),
                'empleado_fecha_sin_estimacion' => count($undecided),
            ]);
        }

        $now    = Carbon::now();
        $closed = 0;

        foreach ($openShifts as $shift) {
            $factorialEmployeeId = (int) ($shift['employee_id'] ?? 0);
            $employee = $employeesByFactorialId[$factorialEmployeeId] ?? null;
            if (!$employee) continue;

            $date    = $shift['date'] ?? null;
            $clockIn = $shift['clock_in'] ?? null; // viene como datetime ISO ("2000-01-01T08:00:00.000Z") en open_shifts
            if (!$date || !$clockIn) continue;

            try {
                $clockInAt = Carbon::parse($date . ' ' . Carbon::parse($clockIn)->format('H:i:s'));
            } catch (\Throwable) {
                continue;
            }

            // Estimación ilegible o truncada: no se adivina, se reintenta luego.
            if (isset($undecided[$factorialEmployeeId . '_' . $date])) continue;

            // Respuesta COMPLETA sin fila para el empleado: comportamiento
            // documentado en CLAUDE.md (8 h por defecto, sin_horario). Para
            // checkin_only no cerrar nunca reintroduciría el bloqueo open_shift.
            $estimate       = $estimatedByKey[$factorialEmployeeId . '_' . $date] ?? null;
            $expectedMinutes = (int) ($estimate['expected_minutes'] ?? 0);

            // expected_minutes = 0 ya no se trata siempre igual: el campo
            // "source" de estimated_times sí distingue un caso real.
            //   - source=work_schedule + expected_minutes=0: descanso
            //     confirmado — verificado contra la API real (ago 2026) que
            //     el MISMO empleado con este mismo source marca >0 en sus
            //     días laborables, así que un 0 aquí es un dato fiable, no
            //     "sin horario cargado". Un turno abierto en ese día es una
            //     anomalía real (alguien marcó entrada en su descanso) que
            //     merece revisión humana — no adivinamos cuánto trabajó, solo
            //     cerramos con el fallback y lo marcamos para revisar.
            //   - Cualquier otro source (ej. shift_management, turnos
            //     rotativos): expected_minutes sale en 0 incluso en días
            //     laborables cuando el turno aún no está cargado en
            //     Factorial — sigue sin poder distinguirse de un día libre
            //     real bajo ese esquema. Mismo tratamiento que antes.
            $source = $estimate['source'] ?? null;

            if ($expectedMinutes > 0) {
                $closeAt = $clockInAt->copy()->addMinutes($expectedMinutes);
                $reason  = 'con_horario';
            } elseif ($source === 'work_schedule') {
                $closeAt = $clockInAt->copy()->addMinutes(self::DEFAULT_SHIFT_MINUTES);
                $reason  = 'descanso_confirmado';
            } else {
                $closeAt = $clockInAt->copy()->addMinutes(self::DEFAULT_SHIFT_MINUTES);
                $reason  = 'sin_horario';
            }

            $threshold = $closeAt->copy()->addMinutes(self::MARGIN_MINUTES);

            if ($now->lt($threshold)) continue; // aún dentro del margen, no tocar

            CloseForgottenShiftJob::dispatch(
                $employee->id,
                (int) $shift['id'],
                $closeAt->format('Y-m-d H:i:s'),
                (bool) $config->checkin_only,
                $reason,
            );

            $closed++;
        }

        return $closed;
    }
}
