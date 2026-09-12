<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\AttendanceEmployeeGuard;
use App\Services\FactorialService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Envía un marcaje a Factorial.
 *
 * Principio (D1 «Nómina», 2026-09-12): contener, no adivinar. Si el fallback no
 * puede decidir con certeza qué quería decir un marcaje, NO escribe en Factorial:
 * marca el registro `failed` con una nota que dice qué turno lo bloquea y desde
 * cuándo. Un fallo visible es recuperable; un turno corrido en silencio es
 * nómina mal pagada.
 */
class SyncAttendanceToFactorial implements ShouldQueue
{
    use Queueable;

    public int $tries  = 1;

    /**
     * Techo duro de attendance.max_shift_hours. updateShift() sólo manda la
     * HORA (H:i:s): Factorial interpreta una salida con hora menor que la
     * entrada como «día siguiente». Eso sólo es inequívoco por debajo de 24 h.
     */
    private const MAX_SHIFT_HOURS_CEILING = 23;

    /** true en cuanto este intento dejó el registro en synced o failed (H02). */
    private bool $settled = false;

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

        // Guardarraíl H01 dentro del job: cubre TODAS las vías de despacho
        // (ingesta en caliente ATTLOG/rtlog, CSV, resolve, tablero), no sólo
        // las que ya lo llamaban antes de despachar.
        if (!AttendanceEmployeeGuard::canDispatch($log, 'SyncAttendanceToFactorial')) {
            $this->fail($log, "Guardarraíl H01: el empleado {$log->factorial_employee_id} no pertenece al cliente {$log->client_id} de este registro; no se envió a Factorial");
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

        // H02: todo camino termina en fail() o markSynced(). Cualquier excepción
        // que no haya dejado ya un estado final (ConnectionException, refresco
        // OAuth fallido, un GET dentro del fallback…) marca `failed` antes de
        // propagarse; antes el registro se quedaba `resolved` para siempre,
        // invisible en el tablero.
        try {
            $this->sync($log, $employee, $service);
        } catch (\Throwable $e) {
            if (!$this->settled) {
                $this->fail($log, 'Error inesperado: ' . $this->describe($e));
            }
            throw $e;
        }
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
        } catch (RequestException $e) {
            $this->fallback($log, $employee, $service, $e);
            return;
        }

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
    }

    /**
     * El método directo fue rechazado. Primero idempotencia (el job pudo haber
     * creado el turno en un intento anterior que murió antes de guardar), luego
     * el overwrite del turno abierto. Cualquier excepción aquí termina en fail().
     */
    private function fallback(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service, RequestException $e): void
    {
        $primaryError = $this->extractErrorMessage($e);

        try {
            $existing = $this->findMatchingShift($service, $employee->factorial_id, $log);
            if ($existing) {
                $this->markSynced($log, $existing['id'], 'idempotente - turno ya existía en Factorial');
                return;
            }

            Log::warning('SyncAttendanceToFactorial: método directo falló, intentando overwrite', [
                'attendance_log_id' => $log->id,
                'http_status'       => $e->response->status(),
                'error'             => $primaryError,
            ]);

            $this->tryOverwrite($log, $employee, $service, $primaryError);

        } catch (\Throwable $t) {
            if (!$this->settled) {
                $this->fail($log, "Directo: {$primaryError} | Fallback: {$this->describe($t)}");
            }
            throw $t;
        }
    }

    // ── Fallback: sobreescribir el turno abierto ───────────────────
    //
    // El biométrico tiene prioridad sobre cualquier turno abierto sea cual sea
    // su in_source (API, web, móvil) — pero sólo dentro de reglas que no
    // adivinan (D1, riesgos R02/R03):
    //   · el turno abierto se busca con el endpoint nativo open_shifts, sea de
    //     la fecha que sea, y sólo se toca si su clock_in está a menos de
    //     attendance.max_shift_hours del marcaje;
    //   · una entrada nunca mueve el clock_in hacia más tarde (la primera
    //     entrada gana); sí puede adelantarlo dentro del mismo día;
    //   · una salida nunca cierra un turno que empezó después de ella.
    // El antiguo tercer fallback (corregir un turno YA CERRADO) está
    // desactivado para todos los tipos: elegía el candidato con first() sin
    // orden ni verificación de solapes y, para entradas, escribía un clock_in
    // posterior al clock_out.

    private function tryOverwrite(AttendanceLog $log, FactorialEmployee $employee, FactorialService $service, string $primaryError): void
    {
        $isEntry = in_array($log->check_type, ['check_in', 'break_out'], true);
        $isExit  = in_array($log->check_type, ['check_out', 'break_in'], true);

        if (!$isEntry && !$isExit) {
            $this->fail($log, "check_type no soportado: {$log->check_type}");
            return;
        }

        $factorialId = (int) $employee->factorial_id;
        $open        = $service->fetchOpenShifts([$factorialId]);

        if ($open['truncated_ids'] !== []) {
            $this->fail($log, "open_shifts devolvió una respuesta truncada para el empleado: no se puede saber qué turno está abierto; revisar. Error original: {$primaryError}");
            return;
        }

        $shifts = collect($open['data'])
            ->filter(fn ($s) => (int) ($s['employee_id'] ?? 0) === $factorialId)
            ->values();

        if ($shifts->isEmpty()) {
            $this->fail($log, "Sin turno abierto para sobreescribir. Error original: {$primaryError}");
            return;
        }

        if ($shifts->count() > 1) {
            $ids = $shifts->pluck('id')->implode(', ');
            $this->fail($log, "Varios turnos abiertos a la vez ({$ids}): no se elige uno a ciegas; revisar. Error original: {$primaryError}");
            return;
        }

        $shift     = $shifts->first();
        $clockInAt = $this->shiftClockIn($shift);

        if (!$clockInAt) {
            $this->fail($log, "El turno abierto {$shift['id']} no trae clock_in legible; revisar. Error original: {$primaryError}");
            return;
        }

        $punchAt    = $log->occurred_at;
        $elapsedSec = $punchAt->getTimestamp() - $clockInAt->getTimestamp(); // > 0: marcaje posterior a la entrada del turno
        $since      = $clockInAt->format('Y-m-d H:i');

        // R02: turno de otro día laboral. No se cierra ni se mueve con este
        // marcaje (crearía un turno de varios días). Lo cierra el cierre
        // automático o una persona.
        if (abs($elapsedSec) > $this->maxShiftHours() * 3600) {
            $hours = intdiv(abs($elapsedSec), 3600);
            $this->fail($log, "Turno abierto desde {$since} ({$hours} h) bloquea este marcaje: revisar. Turno Factorial {$shift['id']}. Error original: {$primaryError}");
            return;
        }

        if ($isEntry) {
            $sameMinute = $clockInAt->format('Y-m-d H:i') === $punchAt->format('Y-m-d H:i');

            if ($sameMinute) {
                // El turno ya refleja esta entrada: nada que escribir.
                $this->markSynced($log, (int) $shift['id'], 'idempotente - entrada ya registrada en el turno abierto');
                return;
            }

            if ($elapsedSec > 0) {
                // R03: una entrada nunca mueve el clock_in hacia más tarde.
                // Con etiquetas invertidas (EPRECSA) es una salida marcada como
                // entrada; tiene que verse, no descartarse.
                $shown = $clockInAt->isSameDay($punchAt) ? $clockInAt->format('H:i') : $since;
                $this->fail($log, "Entrada posterior a la ya registrada ({$shown}); posible salida marcada como entrada. Turno Factorial {$shift['id']}. Error original: {$primaryError}");
                return;
            }

            // Marcaje anterior: la primera entrada gana, pero sólo dentro del
            // mismo día — updateShift() sólo lleva la hora, cambiar de fecha no
            // se puede expresar.
            if (!$clockInAt->isSameDay($punchAt)) {
                $this->fail($log, "Entrada anterior a un turno abierto de otro día ({$since}): no se mueve de fecha; revisar. Turno Factorial {$shift['id']}. Error original: {$primaryError}");
                return;
            }
        } elseif ($elapsedSec <= 0) {
            $this->fail($log, "Salida anterior al inicio del turno abierto ({$since}): no se cierra un turno antes de que empiece; revisar. Turno Factorial {$shift['id']}. Error original: {$primaryError}");
            return;
        }

        $shift = $this->withShiftDetails($service, $factorialId, $shift);

        // Si el turno ya fue editado por nuestro biométrico para este mismo
        // tipo de operación, no volvemos a tocarlo — el segundo fichaje es
        // un doble-golpe en el equipo y no debe sobreescribir el primero.
        $marker = "Editado por biométrico SFT: {$log->check_type}";
        if (str_contains((string) ($shift['observations'] ?? ''), $marker)) {
            $this->fail($log, "Turno ya editado por biométrico SFT para {$log->check_type}. Error original: {$primaryError}");
            return;
        }

        $time          = $punchAt->format('H:i:s');
        $updatePayload = $isEntry ? ['clock_in' => $time] : ['clock_out' => $time];
        $updatePayload['observations'] = $marker;

        $updated     = $service->updateShift((int) $shift['id'], $updatePayload);
        $confirmedId = $updated['id'] ?? null;

        if (!$confirmedId) {
            $this->fail($log, "Factorial no confirmó la actualización del turno {$shift['id']}");
            return;
        }

        $inSource = $shift['in_source'] ?? 'api/biométrico';
        $this->markSynced($log, (int) $confirmedId, "overwrite ({$inSource})");

        Log::info('SyncAttendanceToFactorial: OK (overwrite)', [
            'attendance_log_id'  => $log->id,
            'check_type'         => $log->check_type,
            'factorial_shift_id' => $shift['id'],
            'shift_clock_in'     => $since,
            'in_source'          => $inSource,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function maxShiftHours(): int
    {
        return min(max((int) config('attendance.max_shift_hours', 20), 1), self::MAX_SHIFT_HOURS_CEILING);
    }

    /**
     * Fecha y hora de entrada de un turno. open_shifts devuelve clock_in como
     * datetime ISO con fecha ficticia ("2000-01-01T08:00:00.000Z") y la fecha
     * real en `date`; shifts lo devuelve como "08:00:00". Mismo criterio que
     * CloseForgottenShifts: la hora se toma tal cual, como hora local.
     */
    private function shiftClockIn(array $shift): ?Carbon
    {
        $date = $shift['date'] ?? null;
        $raw  = (string) ($shift['clock_in'] ?? '');

        if (!$date || $raw === '') return null;

        $timePart = str_contains($raw, 'T') ? substr($raw, strpos($raw, 'T') + 1) : $raw;

        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?/', $timePart, $m)) return null;

        try {
            return Carbon::parse(sprintf('%s %s:%s:%s', $date, $m[1], $m[2], $m[3] ?? '00'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * open_shifts no garantiza traer `observations`/`in_source` (la forma que
     * usan las pruebas del cierre automático no los trae). Sin `observations`
     * la marca «Editado por biométrico SFT» no se podría comprobar, así que se
     * completa con el turno de `shifts` de esa fecha, sólo si falta la clave.
     */
    private function withShiftDetails(FactorialService $service, int $factorialEmployeeId, array $shift): array
    {
        if (array_key_exists('observations', $shift) || empty($shift['date'])) {
            return $shift;
        }

        $full = collect($service->getShifts([
            'employee_ids' => [$factorialEmployeeId],
            'start_on'     => $shift['date'],
            'end_on'       => $shift['date'],
        ]))->first(fn ($s) => (int) ($s['id'] ?? 0) === (int) $shift['id']);

        return $full
            ? array_merge($shift, array_intersect_key($full, array_flip(['observations', 'in_source'])))
            : $shift;
    }

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

    private function describe(\Throwable $e): string
    {
        if ($e instanceof RequestException) {
            return $this->extractErrorMessage($e);
        }

        return class_basename($e) . ': ' . $e->getMessage();
    }

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

    private function markSynced(AttendanceLog $log, ?int $shiftId, string $note): void
    {
        $log->update([
            'factorial_shift_id' => $shiftId,
            'sync_status'        => 'synced',
            'processed_at'       => now(),
            'sync_error'         => null,
            'sync_note'          => $note,
        ]);
        $this->settled = true;

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
        $this->settled = true;

        Log::error('SyncAttendanceToFactorial: FAILED', [
            'attendance_log_id' => $log->id,
            'error'             => $error,
        ]);
    }
}
