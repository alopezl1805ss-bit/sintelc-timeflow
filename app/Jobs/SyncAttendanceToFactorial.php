<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\FactorialService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

class SyncAttendanceToFactorial implements ShouldQueue
{
    use Queueable;

    public int $tries  = 1;

    public function __construct(
        public readonly int $attendanceLogId
    ) {}

    public function handle(): void
    {
        $log = AttendanceLog::find($this->attendanceLogId);

        if (!$log) {
            Log::warning('SyncAttendanceToFactorial: log no encontrado', ['id' => $this->attendanceLogId]);
            return;
        }

        if ($log->sync_status === 'synced') return;

        if (!$log->factorial_employee_id) {
            $this->fail($log, "factorial_employee_id no resuelto para employee_code: {$log->employee_code}");
            return;
        }

        $employee = FactorialEmployee::find($log->factorial_employee_id);
        if (!$employee) {
            $this->fail($log, "No se encontró factorial_employee_id: {$log->factorial_employee_id}");
            return;
        }

        $connection = FactorialConnection::find($employee->factorial_connection_id);
        if (!$connection) {
            $this->fail($log, "No se encontró factorial_connection para empleado: {$employee->id}");
            return;
        }

        if (!$log->check_type) {
            $this->fail($log, 'check_type no definido en el log');
            return;
        }

        $service = new FactorialService($connection);
        $this->sync($log, $employee, $service);
    }

    // ── Flujo principal ────────────────────────────────────────────

    private function sync(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service): void
    {
        $workplaceId = $log->biometricSource?->factorial_location_id;

        $payload = [
            'employee_id' => $employee->factorial_id,
            'now'         => $log->occurred_at->format('Y-m-d\TH:i:s'),
        ];

        if ($workplaceId) {
            $payload['workplace_id']  = $workplaceId;
            $payload['location_type'] = 'office';
        }

        try {
            $response = match ($log->check_type) {
                'check_in', 'break_out' => $service->clockIn($payload),
                'check_out', 'break_in' => $service->clockOut($payload),
                default                 => null,
            };

            if ($response === null) {
                $this->fail($log, "check_type no soportado: {$log->check_type}");
                return;
            }

            $shiftId = $response['id'] ?? null;
            if (!$shiftId) {
                // La respuesta llegó pero sin ID — verificar si Factorial igualmente creó el turno
                $existing = $this->findMatchingShift($service, $employee->factorial_id, $log);
                if ($existing) {
                    $this->markSynced($log, $existing['id'], 'idempotente - turno confirmado sin ID en respuesta');
                    return;
                }
                $this->fail($log, 'Factorial no devolvió ID de turno en la respuesta directa');
                return;
            }

            $this->markSynced($log, $shiftId, 'directo');

        } catch (RequestException $e) {
            $message = $this->extractErrorMessage($e);

            // Antes de intentar overwrite, verificar idempotencia:
            // el job pudo haber creado el turno en Factorial en una ejecución anterior
            // pero fallar antes de actualizar nuestra DB.
            $existing = $this->findMatchingShift($service, $employee->factorial_id, $log);
            if ($existing) {
                $this->markSynced($log, $existing['id'], 'idempotente - turno ya existía en Factorial');
                return;
            }

            Log::warning('SyncAttendanceToFactorial: método directo falló, intentando overwrite', [
                'attendance_log_id' => $log->id,
                'http_status'       => $e->response->status(),
                'error'             => $message,
            ]);

            $this->tryOverwrite($log, $employee, $service, $message);

        } catch (\Throwable $e) {
            // Solo marcar como fallido si el sync no había completado ya.
            // Evita que errores de logging (permisos) sobrescriban un status synced.
            $log->refresh();
            if ($log->sync_status !== 'synced') {
                $this->fail($log, $e->getMessage());
            }
            throw $e;
        }
    }

    // ── Fallback: sobreescribir turno existente ────────────────────
    //
    // La API tiene mayor jerarquía que la plataforma web/móvil.
    // Si hay un turno abierto (sea cual sea su in_source), lo sobreescribimos
    // con el timestamp del biométrico.

    private function tryOverwrite(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service, string $primaryError): void
    {
        try {
            // Buscar turno abierto del empleado en la fecha del registro
            // También revisamos el día anterior por si el turno cruzó medianoche
            $openShift = $this->findOpenShift($service, $employee->factorial_id, $log->occurred_at);

            if (!$openShift) {
                // Fallback: si no hay turno abierto, buscar uno cerrado ese mismo día
                // cuyo clock_out haya sido anterior al ponche real — indica que Factorial
                // cerró el turno de más temprano (ej. por horario/contrato, o por break mal cerrado).
                // Correger el clock_out a la hora real es más preciso que fallar.
                $closedShift = $this->findClosedShiftToCorrect($service, $employee->factorial_id, $log->occurred_at, $log->check_type);
                if ($closedShift) {
                    $this->correctClosedShift($log, $employee, $service, $closedShift);
                    return;
                }

                $this->fail($log, "Sin turno abierto para sobreescribir. Error original: {$primaryError}");
                return;
            }

            // El biométrico tiene prioridad sobre cualquier turno abierto,
            // independientemente de su origen (API, biométrico, web, app).
            // null                → creado vía API/biométrico → SÍ permitir
            // desktop             → web de Factorial          → SÍ permitir
            // mobile              → app móvil Factorial       → SÍ permitir
            // mobile_geolocation  → app móvil con geoloc.     → SÍ permitir
            $inSource = $openShift['in_source'] ?? 'api/biométrico';

            // Si el turno ya fue editado por nuestro biométrico para este mismo
            // tipo de operación, no volvemos a tocarlo — el segundo fichaje es
            // un doble-golpe en el equipo y no debe sobreescribir el primero.
            $marker       = "Editado por biométrico SFT: {$log->check_type}";
            $observations = $openShift['observations'] ?? '';
            if (str_contains((string) $observations, $marker)) {
                $this->fail($log, "Turno ya editado por biométrico SFT para {$log->check_type}. Error original: {$primaryError}");
                return;
            }

            $time = $log->occurred_at->format('H:i:s');

            $updatePayload = match ($log->check_type) {
                'check_in', 'break_out' => ['clock_in'  => $time],
                'check_out', 'break_in' => ['clock_out' => $time],
                default                 => null,
            };

            if ($updatePayload === null) {
                $this->fail($log, "check_type no soportado: {$log->check_type}");
                return;
            }

            $updatePayload['observations'] = $marker;

            $updated = $service->updateShift($openShift['id'], $updatePayload);

            $confirmedId = $updated['id'] ?? null;
            if (!$confirmedId) {
                $this->fail($log, "Factorial no confirmó la actualización del turno {$openShift['id']}");
                return;
            }

            $this->markSynced($log, $confirmedId, "overwrite ({$inSource})");

            Log::info('SyncAttendanceToFactorial: OK (overwrite)', [
                'attendance_log_id'  => $log->id,
                'check_type'         => $log->check_type,
                'factorial_shift_id' => $openShift['id'],
                'in_source'          => $inSource,
            ]);

        } catch (RequestException $e) {
            $message = $this->extractErrorMessage($e);

            $this->fail($log, "Directo: {$primaryError} | Overwrite: {$message}");
            throw $e;

        } catch (\Throwable $e) {
            $this->fail($log, "Directo: {$primaryError} | Overwrite: {$e->getMessage()}");
            throw $e;
        }
    }

    // ── Fallback: corregir turno cerrado de más temprano ────────────────────

    /**
     * Busca un turno cerrado en la misma fecha (o la anterior, si es nocturno)
     * cuyo clock_out sea anterior a la hora del ponche real.
     *
     * Detecta turnos que Factorial cerró antes de tiempo (p.ej. por horario/contrato,
     * o por un break sin cierre real), y que la entrada/salida real llegó después.
     */
    private function findClosedShiftToCorrect(FactorialService $service, int $factorialEmployeeId, \Carbon\Carbon $logTime, string $checkType): ?array
    {
        // Para check_out: buscar un turno cuyo clock_out sea anterior a la hora real
        // (es decir, ya cerrado cuando la salida debería haber sido después).
        // Para check_in: buscar un turno del día anterior (turno nocturno cerrado antes del check_in real).
        $field      = in_array($checkType, ['check_out', 'break_in']) ? 'clock_out' : 'clock_in';
        $targetDate = $logTime->format('Y-m-d');
        $logTimeStr = $logTime->format('H:i');

        // Buscar en el mismo día
        $shifts = $service->getShifts([
            'employee_ids' => [$factorialEmployeeId],
            'start_on'     => $targetDate,
            'end_on'       => $targetDate,
        ]);

        $candidate = collect($shifts)->filter(
            fn($s) => (int) $s['employee_id'] === $factorialEmployeeId
                   && $s['date'] === $targetDate
                   && $s['clock_out'] !== null  // debe estar cerrado
                   && substr($s['clock_out'] ?? '', 0, 5) < $logTimeStr  // clock_out antes de la hora real
        )->first();

        if ($candidate) return $candidate;

        // Para turnos nocturnos (check_in madrugada del 17, pero el turno empezó el 16):
        // buscar turno del día anterior cuyo clock_out sea de madrugada.
        if (in_array($checkType, ['check_in', 'break_out']) && (int) substr($logTimeStr, 0, 2) < 6) {
            $prevDate = $logTime->copy()->subDay()->format('Y-m-d');
            $shifts = $service->getShifts([
                'employee_ids' => [$factorialEmployeeId],
                'start_on'     => $prevDate,
                'end_on'       => $prevDate,
            ]);

            return collect($shifts)->filter(
                fn($s) => (int) $s['employee_id'] === $factorialEmployeeId
                       && $s['date'] === $prevDate
                       && $s['clock_out'] !== null
                       && substr($s['clock_out'] ?? '', 0, 5) >= '20:00'  // cerrado de noche
            )->first();
        }

        return null;
    }

    /**
     * Corrige un turno cerrado de más temprano, actualizando el clock_out
     * (o clock_in para break_out) a la hora real del ponche.
     */
    private function correctClosedShift(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service, array $shift): void
    {
        try {
            $time = $log->occurred_at->format('H:i:s');

            $updatePayload = match ($log->check_type) {
                'check_in', 'break_out' => ['clock_in'  => $time],
                'check_out', 'break_in' => ['clock_out' => $time],
                default                 => null,
            };

            if ($updatePayload === null) {
                $this->fail($log, "check_type no soportado: {$log->check_type}");
                return;
            }

            $marker = "Editado por biométrico SFT: {$log->check_type}";
            $updatePayload['observations'] = $marker;

            $updated = $service->updateShift($shift['id'], $updatePayload);
            $confirmedId = $updated['id'] ?? null;

            if (!$confirmedId) {
                $this->fail($log, "Factorial no confirmó la corrección del turno {$shift['id']}");
                return;
            }

            $this->markSynced($log, $confirmedId, "corregido (turno cerrado de más temprano)");

            Log::info('SyncAttendanceToFactorial: OK (corrección de cierre anticipado)', [
                'attendance_log_id'  => $log->id,
                'check_type'         => $log->check_type,
                'factorial_shift_id' => $shift['id'],
                'original_time'      => $shift[$log->check_type === 'check_out' ? 'clock_out' : 'clock_in'],
                'corrected_time'     => $time,
            ]);

        } catch (RequestException $e) {
            $message = $this->extractErrorMessage($e);
            $this->fail($log, "No se pudo corregir el turno cerrado de más temprano: {$message}");
            throw $e;
        } catch (\Throwable $e) {
            $this->fail($log, "Error corrigiendo turno: {$e->getMessage()}");
            throw $e;
        }
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Extrae un mensaje legible del error que devuelve Factorial.
     *
     * Factorial usa distintas formas según el tipo de rechazo:
     *   - {"errors":{"exception":["..."]}}  → error de validación/negocio
     *   - {"errors":{"errors":["..."]}}     → error de política/permisos
     *     (ej. "Forbidden by Attendance::EmployeePolicy::ClockInOut",
     *     visto en pruebas reales de agosto 2026)
     * Si ninguna coincide, cae a $body['message'] y luego al mensaje HTTP genérico.
     */
    private function extractErrorMessage(RequestException $e): string
    {
        $body = $e->response->json() ?? [];

        return $body['errors']['exception'][0]
            ?? $body['errors']['errors'][0]
            ?? $body['message']
            ?? $e->getMessage();
    }

    /**
     * Busca un turno sin clock_out en la fecha exacta del log.
     * Solo se revisa el día del registro — nunca días anteriores.
     *
     * NOTA: la API de Factorial ignora los query params employee_id y date,
     * devuelve todos los turnos de la empresa. Filtramos en PHP para garantizar
     * que solo tocamos turnos del empleado correcto en la fecha correcta.
     */
    /**
     * Verifica si Factorial ya tiene un turno que coincida con el tiempo del log.
     * Usado para idempotencia: detecta el caso en que el job creó el turno en Factorial
     * pero crasheó antes de actualizar nuestra DB.
     *
     * Para check_in / break_out → busca un turno con clock_in == hora del log.
     * Para check_out / break_in → busca un turno con clock_out == hora del log.
     */
    private function findMatchingShift(FactorialService $service, int $factorialEmployeeId, AttendanceLog $log): ?array
    {
        $targetDate = $log->occurred_at->format('Y-m-d');
        $logTime    = $log->occurred_at->format('H:i');
        $field      = in_array($log->check_type, ['check_in', 'break_out']) ? 'clock_in' : 'clock_out';

        $shifts = $service->getShifts([
            'employee_ids' => [$factorialEmployeeId],
            'start_on'     => $targetDate,
            'end_on'       => $targetDate,
        ]);

        return collect($shifts)->first(
            fn($s) => (int) $s['employee_id'] === $factorialEmployeeId
                   && $s['date'] === $targetDate
                   && substr($s[$field] ?? '', 0, 5) === $logTime
                   && ($s['in_source'] ?? null) === null  // solo turnos creados por nuestra API
        );
    }

    private function findOpenShift(FactorialService $service, int $factorialEmployeeId, \Carbon\Carbon $date): ?array
    {
        $targetDate = $date->format('Y-m-d');

        $shifts = $service->getShifts([
            'employee_ids' => [$factorialEmployeeId],
            'start_on'     => $targetDate,
            'end_on'       => $targetDate,
        ]);

        // Filtro defensivo: aunque la API devuelva más registros de los esperados,
        // solo aceptamos turnos del empleado correcto en la fecha exacta.
        // Si hay varios abiertos, tomamos el de clock_in más temprano.
        return collect($shifts)->filter(
            fn($s) => $s['clock_out'] === null
                   && (int) $s['employee_id'] === $factorialEmployeeId
                   && $s['date'] === $targetDate
        )->sortBy('clock_in')->first();
    }

    private function markSynced(AttendanceLog $log, ?int $shiftId, string $note): void
    {
        $log->update([
            'factorial_shift_id' => $shiftId,
            'sync_status'        => 'synced',
            'processed_at'       => now(),
            'sync_error'         => null,
            'sync_note'          => $note,
        ]);

        Log::info('SyncAttendanceToFactorial: OK', [
            'attendance_log_id'  => $log->id,
            'check_type'         => $log->check_type,
            'factorial_shift_id' => $shiftId,
            'note'               => $note,
        ]);
    }

    private function fail(AttendanceLog $log, string $error): void
    {
        $log->update(['sync_status' => 'failed', 'sync_error' => $error]);

        Log::error('SyncAttendanceToFactorial: FAILED', [
            'attendance_log_id' => $log->id,
            'error'             => $error,
        ]);
    }
}
