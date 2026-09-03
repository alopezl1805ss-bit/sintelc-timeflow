<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\FactorialService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Cierra un turno que quedó abierto más allá de lo esperado — ya sea porque
 * el cliente solo registra entrada (checkin_only) o porque un empleado
 * olvidó marcar salida en un cliente normal.
 *
 * A diferencia de SyncAttendanceToFactorial (tries=1), este job SÍ lleva
 * reintentos: si se pierde, el turno se queda abierto y el siguiente
 * check_in de ese empleado vuelve a fallar con "open_shift" — el problema
 * que todo este mecanismo existe para evitar.
 *
 * Despachado por el comando attendance:close-forgotten-shifts, que ya
 * decidió (usando open_shifts + estimated_times) que este turno específico
 * debe cerrarse. Este job solo ejecuta el cierre, con una verificación de
 * idempotencia: si para cuando corre el turno ya se cerró (p. ej. el
 * empleado marcó salida real mientras tanto), no hace nada.
 *
 * También inserta un AttendanceLog sintético (no viene de ningún dispositivo
 * real — ver virtualSource()) para que el cierre sea visible en la pantalla
 * de "Registros de asistencia" del cliente, con una nota debajo del estado.
 */
class CloseForgottenShiftJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public array $backoff = [30, 120, 300]; // 30s, 2min, 5min

    // Prefijo usado por la vista de registros para decidir si debe mostrar
    // la nota visible bajo el badge de estado (no solo en el tooltip).
    public const NOTE_PREFIX = 'Cierre automático';

    public function __construct(
        public readonly int $factorialEmployeeId,
        public readonly int $shiftId,
        public readonly string $closeAt,     // 'Y-m-d H:i:s', ya calculado por el comando (entrada + horas_esperadas, SIN el margen de 4h)
        public readonly bool $checkinOnly,
        public readonly string $reason,      // 'sin_horario' | 'con_horario' — solo para logging/observations
    ) {}

    public function handle(): void
    {
        $employee = FactorialEmployee::find($this->factorialEmployeeId);
        if (!$employee) {
            Log::warning('CloseForgottenShiftJob: empleado no encontrado', ['factorial_employee_id' => $this->factorialEmployeeId]);
            return;
        }

        $connection = FactorialConnection::find($employee->factorial_connection_id);
        if (!$connection) {
            Log::warning('CloseForgottenShiftJob: conexión no encontrada', ['employee_id' => $employee->id]);
            return;
        }

        $service = new FactorialService($connection);

        // Idempotencia: confirmar que el turno SIGUE abierto antes de tocarlo.
        // Puede que el empleado haya marcado salida real entre que el comando
        // detectó el turno abierto y que este job corrió (más aún con reintentos
        // de hasta varios minutos de por medio).
        $stillOpen = collect($service->getOpenShifts([$employee->factorial_id]))
            ->contains(fn($s) => (int) $s['id'] === $this->shiftId);

        if (!$stillOpen) {
            Log::info('CloseForgottenShiftJob: turno ya no está abierto, nada que hacer', [
                'shift_id' => $this->shiftId,
                'employee' => $employee->full_name,
            ]);
            return;
        }

        $factorialNote = 'No se registró salida (cierre automático SFT — ' . $this->reason . ')';

        $updated = $service->updateShift($this->shiftId, [
            'clock_out'    => Carbon::parse($this->closeAt)->format('H:i:s'),
            'observations' => $factorialNote,
        ]);

        $confirmedId = $updated['id'] ?? null;
        if (!$confirmedId) {
            Log::error('CloseForgottenShiftJob: Factorial no confirmó el cierre', [
                'shift_id' => $this->shiftId,
                'employee' => $employee->full_name,
            ]);
            throw new \RuntimeException("Factorial no confirmó el cierre del turno {$this->shiftId}");
        }

        // checkin_only=true → rutina esperada, no requiere atención — EXCEPTO
        // si el motivo es 'descanso_confirmado': ahí sí hay evidencia real
        // (estimated_times.source=work_schedule) de que este turno se abrió
        // en un día sin horas planificadas para ese empleado, lo cual es una
        // anomalía real sin importar si el cliente es checkin_only o no.
        // checkin_only=false → excepción real (se le olvidó marcar salida en
        // un cliente que sí espera checkout). Sin edit_timesheet_requests
        // verificado todavía, la nota visible en attendance_logs es la única
        // señal de "revisar esto" — ver CLAUDE.md para el seguimiento pendiente.
        $needsReview = !$this->checkinOnly || $this->reason === 'descanso_confirmado';

        $note = match (true) {
            $this->reason === 'descanso_confirmado' => self::NOTE_PREFIX . ' — entrada en día de descanso confirmado, revisar horas',
            $needsReview                            => self::NOTE_PREFIX . ' — turno olvidado, revisar horas',
            default                                 => self::NOTE_PREFIX . ' — solo entrada (rutina)',
        };

        $this->recordAttendanceLog($employee, $note);

        if ($needsReview) {
            Log::warning('CloseForgottenShiftJob: turno olvidado cerrado — REQUIERE REVISIÓN', [
                'shift_id'     => $this->shiftId,
                'employee'     => $employee->full_name,
                'factorial_id' => $employee->factorial_id,
                'client_id'    => $employee->client_id,
                'closed_at'    => $this->closeAt,
                'reason'       => $this->reason,
            ]);
        } else {
            Log::info('CloseForgottenShiftJob: cierre de rutina (checkin_only) OK', [
                'shift_id'  => $this->shiftId,
                'employee'  => $employee->full_name,
                'closed_at' => $this->closeAt,
            ]);
        }
    }

    private function recordAttendanceLog(FactorialEmployee $employee, string $note): void
    {
        try {
            AttendanceLog::create([
                'client_id'             => $employee->client_id,
                'biometric_source_id'   => $this->virtualSource($employee->client_id)->id,
                'factorial_employee_id' => $employee->id,
                'factorial_shift_id'    => $this->shiftId,
                'shift_closed'          => true,
                'employee_code'         => $this->resolveEmployeeCode($employee),
                'check_type'            => 'check_out',
                'occurred_at'           => $this->closeAt,
                'processed_at'          => now(),
                'sync_status'           => 'synced',
                'sync_note'             => $note,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Choque con el índice único (mismo empleado/fuente/hora ya registrado) —
            // no debería pasar dado el chequeo de idempotencia de arriba, pero si
            // pasa por un reintento simultáneo, no es un error real, solo un no-op.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /**
     * PIN del dispositivo asociado al empleado, si existe un mapeo. Puramente
     * informativo para la columna "Empleado" de la pantalla de registros —
     * este cierre no vino de ningún biométrico real.
     */
    private function resolveEmployeeCode(FactorialEmployee $employee): string
    {
        $code = BiometricUserSync::where('factorial_employee_id', $employee->id)
            ->value('external_employee_code');

        return $code ?? ('FACT-' . $employee->factorial_id);
    }

    /**
     * Dispositivo sintético, uno por cliente, para poder insertar un
     * AttendanceLog sin violar el FK de biometric_source_id. status='virtual'
     * es un valor propio (no 'active'/'inactive') a propósito, para que no
     * lo cuenten las métricas de salud de dispositivos del dashboard.
     */
    private function virtualSource(int $clientId): BiometricSource
    {
        return BiometricSource::firstOrCreate(
            ['client_id' => $clientId, 'serial_number' => "AUTOCLOSE-{$clientId}"],
            [
                'biometric_provider_id' => null,
                'name'                  => 'Cierre automático',
                'status'                => 'virtual',
            ]
        );
    }
}
