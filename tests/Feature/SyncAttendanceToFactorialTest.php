<?php

namespace Tests\Feature;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncAttendanceToFactorialTest extends TestCase
{
    use RefreshDatabase;

    private const SHIFTS_URL    = 'https://api.factorialhr.com/api/2026-04-01/resources/attendance/shifts';
    private const CLOCK_IN_URL  = self::SHIFTS_URL . '/clock_in';
    private const CLOCK_OUT_URL = self::SHIFTS_URL . '/clock_out';
    private const OPEN_SHIFTS_URL = 'https://api.factorialhr.com/api/2026-04-01/resources/attendance/open_shifts';

    public function test_clock_in_success_marks_log_as_synced_directly(): void
    {
        [$log] = $this->makeLog(checkType: 'check_in');

        Http::fake([
            self::CLOCK_IN_URL => Http::response(['id' => 555, 'employee_id' => 111], 200),
        ]);

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('synced', $log->sync_status);
        $this->assertSame(555, $log->factorial_shift_id);
        $this->assertSame('directo', $log->sync_note);
        Http::assertSentCount(1);
    }

    public function test_clock_out_failure_falls_back_to_overwriting_open_shift(): void
    {
        [$log, $employee] = $this->makeLog(checkType: 'check_out', occurredAt: '2026-07-24 18:05:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_OUT_URL) {
                // Respuesta real que reportó Factorial: turno abierto sin clock_out explícito.
                return Http::response(['errors' => ['exception' => ['open_shift']]], 422);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response([
                    'data' => [[
                        'id'           => 900,
                        'employee_id'  => $employee->factorial_id,
                        'date'         => '2026-07-24',
                        'clock_in'     => '2000-01-01T09:00:00.000Z',
                        'clock_out'    => null,
                        'in_source'    => null,
                        'observations' => null,
                    ]],
                ], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::SHIFTS_URL . '/900') {
                return Http::response(['id' => 900], 200);
            }

            return Http::response([], 404);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('synced', $log->sync_status);
        $this->assertSame(900, $log->factorial_shift_id);
        $this->assertStringStartsWith('overwrite', $log->sync_note);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::SHIFTS_URL . '/900'
            && $request['clock_out'] === '18:05:00'
            && str_contains($request['observations'], 'Editado por biométrico SFT: check_out'));
    }

    public function test_clock_in_failure_with_matching_shift_already_in_factorial_is_idempotent(): void
    {
        [$log, $employee] = $this->makeLog(checkType: 'check_in', occurredAt: '2026-07-24 09:00:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_IN_URL) {
                return Http::response(['message' => 'boom'], 500);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                // El job ya había creado este turno en un intento anterior que
                // crasheó antes de guardar el resultado en nuestra DB.
                return Http::response([
                    'data' => [[
                        'id'           => 777,
                        'employee_id'  => $employee->factorial_id,
                        'date'         => '2026-07-24',
                        'clock_in'     => '09:00:00',
                        'clock_out'    => null,
                        'in_source'    => null,
                        'observations' => null,
                    ]],
                ], 200);
            }

            return Http::response([], 404);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('synced', $log->sync_status);
        $this->assertSame(777, $log->factorial_shift_id);
        $this->assertStringContainsString('idempotente', $log->sync_note);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_overwrite_is_skipped_when_shift_was_already_edited_for_the_same_check_type(): void
    {
        [$log, $employee] = $this->makeLog(checkType: 'check_out', occurredAt: '2026-07-24 18:00:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_OUT_URL) {
                return Http::response(['errors' => ['exception' => ['open_shift']]], 422);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response([
                    'data' => [[
                        'id'           => 900,
                        'employee_id'  => $employee->factorial_id,
                        'date'         => '2026-07-24',
                        'clock_in'     => '2000-01-01T09:00:00.000Z',
                        'clock_out'    => null,
                        'in_source'    => 'desktop',
                        // Ya lo tocamos antes para este mismo check_type: el segundo
                        // fichaje es un doble-golpe en el equipo, no debe sobreescribir.
                        'observations' => 'Editado por biométrico SFT: check_out',
                    ]],
                ], 200);
            }

            return Http::response([], 404);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('ya editado por biométrico', $log->sync_error);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_extracts_real_policy_error_format_seen_in_production(): void
    {
        // Formato real devuelto por Factorial para rechazos de política/permisos
        // (ej. Attendance::EmployeePolicy::ClockInOut), distinto del formato
        // {"errors":{"exception":[...]}} usado para errores de validación/negocio.
        // Confirmado en pruebas reales contra producción en agosto 2026.
        [$log] = $this->makeLog(checkType: 'check_in', occurredAt: '2026-07-24 09:00:00');

        Http::fake(function (Request $request) {
            if ($request->url() === self::CLOCK_IN_URL) {
                return Http::response([
                    'errors' => ['errors' => ['Forbidden by Attendance::EmployeePolicy::ClockInOut']],
                ], 403);
            }

            // Sin turno abierto que sobreescribir: el fallback tampoco encuentra nada.
            if ($request->method() === 'GET' && (str_starts_with($request->url(), self::SHIFTS_URL) || str_starts_with($request->url(), self::OPEN_SHIFTS_URL))) {
                return Http::response(['data' => []], 200);
            }

            return Http::response([], 404);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString(
            'Forbidden by Attendance::EmployeePolicy::ClockInOut',
            $log->sync_error
        );
    }

    public function test_fails_without_calling_factorial_when_employee_is_not_mapped(): void
    {
        $client = Client::create(['name' => 'Attendance Client', 'slug' => 'attendance-' . str()->random(8)]);
        $connection = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => 'Connection',
            'resource_owner_type' => 'company',
            'access_token'        => 'test-token',
        ]);
        $source = $this->makeSource($client, $connection);

        $log = AttendanceLog::create([
            'client_id'           => $client->id,
            'biometric_source_id' => $source->id,
            'employee_code'       => '9999',
            'check_type'          => 'check_in',
            'occurred_at'         => '2026-07-24 09:00:00',
            'sync_status'         => 'resolved',
        ]);

        Http::fake();

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('factorial_employee_id no resuelto', $log->sync_error);
        Http::assertNothingSent();
    }

    public function test_fails_without_calling_factorial_for_unsupported_check_type(): void
    {
        [$log] = $this->makeLog(checkType: 'unknown');

        Http::fake();

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('check_type no soportado', $log->sync_error);
        Http::assertNothingSent();
    }

    // ── D1 «Nómina»: turno abierto de otro día (R02) ───────────────

    public function test_salida_cierra_turno_abierto_del_dia_anterior(): void
    {
        // Turno nocturno: entró el 24 a las 22:00, sale el 25 a las 06:00. El
        // fallback viejo sólo miraba la fecha del marcaje (25) y fallaba con
        // «Sin turno abierto para sobreescribir».
        [$log, $employee] = $this->makeLog(checkType: 'check_out', occurredAt: '2026-07-25 06:00:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_OUT_URL) {
                return Http::response(['errors' => ['exception' => ['No fue posible registrar la salida. No existe ningún turno abierto.']]], 422);
            }

            // Forma de open_shifts que usan las pruebas del cierre automático:
            // sin observations ni in_source → el job los completa con shifts.
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [[
                    'id'          => 900,
                    'employee_id' => $employee->factorial_id,
                    'date'        => '2026-07-24',
                    'clock_in'    => '2000-01-01T22:00:00.000Z',
                    'clock_out'   => null,
                    'status'      => 'opened',
                ]]], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                if (str_contains($request->url(), 'start_on=2026-07-24')) {
                    return Http::response(['data' => [[
                        'id' => 900, 'employee_id' => $employee->factorial_id, 'date' => '2026-07-24',
                        'clock_in' => '22:00:00', 'clock_out' => null, 'in_source' => null, 'observations' => null,
                    ]], 'meta' => ['total' => 1]], 200);
                }

                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::SHIFTS_URL . '/900') {
                return Http::response(['id' => 900], 200);
            }

            return Http::response([], 404);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('synced', $log->sync_status);
        $this->assertSame(900, $log->factorial_shift_id);
        $this->assertStringStartsWith('overwrite', $log->sync_note);

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::SHIFTS_URL . '/900'
            && $request['clock_out'] === '06:00:00'
            && str_contains($request['observations'], 'Editado por biométrico SFT: check_out'));
    }

    public function test_salida_no_cierra_turno_abierto_de_hace_mas_de_max_horas(): void
    {
        config(['attendance.max_shift_hours' => 20]);

        [$log, $employee] = $this->makeLog(checkType: 'check_out', occurredAt: '2026-07-24 18:00:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_OUT_URL) {
                return Http::response(['errors' => ['exception' => ['No fue posible registrar la salida. No existe ningún turno abierto.']]], 422);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [$this->openShift(901, $employee->factorial_id, '2026-07-23', '08:00:00')]], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['id' => 901], 200);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('Turno abierto desde 2026-07-23 08:00 (34 h) bloquea este marcaje: revisar', $log->sync_error);
        $this->assertStringContainsString('901', $log->sync_error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    // ── D1 «Nómina»: la primera entrada gana (R03) ─────────────────

    public function test_una_entrada_nunca_mueve_clock_in_hacia_tarde(): void
    {
        // Caso EPRECSA: la «entrada» de las 18:00 es una salida mal etiquetada.
        [$log, $employee] = $this->makeLog(checkType: 'check_in', occurredAt: '2026-07-24 18:00:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_IN_URL) {
                return Http::response(['errors' => ['exception' => ['No fue posible registrar la asistencia. Ya existe un turno en curso.']]], 409);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [$this->openShift(902, $employee->factorial_id, '2026-07-24', '14:30:00')]], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['id' => 902], 200);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('Entrada posterior a la ya registrada (14:30); posible salida marcada como entrada', $log->sync_error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_una_entrada_anterior_si_adelanta_clock_in(): void
    {
        [$log, $employee] = $this->makeLog(checkType: 'check_in', occurredAt: '2026-07-24 07:50:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_IN_URL) {
                return Http::response(['errors' => ['exception' => ['No fue posible registrar la asistencia. Ya existe un turno en curso.']]], 409);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [$this->openShift(903, $employee->factorial_id, '2026-07-24', '08:00:00', 'mobile')]], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::SHIFTS_URL . '/903') {
                return Http::response(['id' => 903], 200);
            }

            return Http::response([], 404);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('synced', $log->sync_status);
        $this->assertSame('overwrite (mobile)', $log->sync_note);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::SHIFTS_URL . '/903'
            && $request['clock_in'] === '07:50:00');
    }

    public function test_tercer_fallback_no_aplica_a_entradas(): void
    {
        // Caso ACERMEX PIN 3943631 (2026-09-02): sin turno abierto, el tercer
        // fallback tomaba el turno cerrado 08:30→16:12 y le escribía
        // clock_in = 17:10, posterior a su clock_out.
        [$log, $employee] = $this->makeLog(checkType: 'check_in', occurredAt: '2026-07-24 17:10:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_IN_URL) {
                return Http::response(['errors' => ['exception' => ['se solapa con el turno de 08:30 a 16:12 del 2026-07-24']]], 422);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => [[
                    'id' => 904, 'employee_id' => $employee->factorial_id, 'date' => '2026-07-24',
                    'clock_in' => '08:30:00', 'clock_out' => '16:12:00', 'in_source' => null, 'observations' => null,
                ]]], 200);
            }

            return Http::response(['id' => 904], 200);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringStartsWith('Sin turno abierto para sobreescribir', $log->sync_error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    public function test_tercer_fallback_tampoco_corrige_salidas(): void
    {
        // Desactivado también para salidas: elegía con first() entre los
        // turnos cerrados del día sin orden ni chequeo de solapes.
        [$log, $employee] = $this->makeLog(checkType: 'check_out', occurredAt: '2026-07-24 18:30:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_OUT_URL) {
                return Http::response(['errors' => ['exception' => ['No fue posible registrar la salida. No existe ningún turno abierto.']]], 422);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => [
                    ['id' => 905, 'employee_id' => $employee->factorial_id, 'date' => '2026-07-24', 'clock_in' => '08:00:00', 'clock_out' => '13:00:00', 'in_source' => null],
                    ['id' => 906, 'employee_id' => $employee->factorial_id, 'date' => '2026-07-24', 'clock_in' => '14:00:00', 'clock_out' => '18:00:00', 'in_source' => null],
                ]], 200);
            }

            return Http::response(['id' => 905], 200);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringStartsWith('Sin turno abierto para sobreescribir', $log->sync_error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    // ── D1 «Nómina»: hueco del catch (H02) y guardarraíl ───────────

    public function test_excepcion_de_conexion_en_fallback_termina_en_failed(): void
    {
        [$log] = $this->makeLog(checkType: 'check_out', occurredAt: '2026-07-24 18:00:00');

        Http::fake(function (Request $request) {
            if ($request->url() === self::CLOCK_OUT_URL) {
                return Http::response(['errors' => ['exception' => ['open_shift']]], 422);
            }

            // findMatchingShift() corría dentro del catch (RequestException):
            // una ConnectionException ahí dejaba el registro `resolved`.
            return Http::failedConnection('cURL error 28: Connection timed out');
        });

        try {
            (new SyncAttendanceToFactorial($log->id))->handle();
            $this->fail('La excepción de conexión debía propagarse para que el job quede en failed_jobs');
        } catch (ConnectionException) {
            // esperado
        }

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('Directo: open_shift | Fallback: ConnectionException', $log->sync_error);
    }

    public function test_job_no_despacha_empleado_de_otro_cliente(): void
    {
        [$log] = $this->makeLog(checkType: 'check_in');

        // Empleado de OTRA empresa grabado en el registro (incidencia H01).
        [, $ajeno] = $this->makeLog(checkType: 'check_in');
        $log->update(['factorial_employee_id' => $ajeno->id]);

        Http::fake();

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('Guardarraíl H01', $log->sync_error);
        Http::assertNothingSent();
    }

    // ── Condiciones de la revisión D1: retención y mensaje de break_out ──

    public function test_cliente_retenido_no_envia_nada_y_los_demas_siguen_igual(): void
    {
        [$retenido] = $this->makeLog(checkType: 'check_in');
        [$normal]   = $this->makeLog(checkType: 'check_in');

        config(['attendance.sync_hold_clients' => [(int) $retenido->client_id]]);

        Http::fake([self::CLOCK_IN_URL => Http::response(['id' => 556, 'employee_id' => 111], 200)]);

        (new SyncAttendanceToFactorial($retenido->id))->handle();

        $retenido->refresh();
        $this->assertSame('retenido', $retenido->sync_status);
        $this->assertSame(SyncAttendanceToFactorial::HOLD_NOTE, $retenido->sync_note);
        $this->assertNull($retenido->factorial_shift_id);
        Http::assertNothingSent();

        (new SyncAttendanceToFactorial($normal->id))->handle();

        $normal->refresh();
        $this->assertSame('synced', $normal->sync_status);
        $this->assertSame('directo', $normal->sync_note);
        Http::assertSentCount(1);
    }

    public function test_break_out_frenado_dice_que_falta_la_salida_a_pausa(): void
    {
        [$log, $employee] = $this->makeLog(checkType: 'break_out', occurredAt: '2026-07-24 14:00:00');

        Http::fake(function (Request $request) use ($employee) {
            if ($request->url() === self::CLOCK_IN_URL) {
                return Http::response(['errors' => ['exception' => ['No fue posible registrar la asistencia. Ya existe un turno en curso.']]], 409);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [$this->openShift(907, $employee->factorial_id, '2026-07-24', '08:00:00')]], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response(['data' => []], 200);
            }

            return Http::response(['id' => 907], 200);
        });

        (new SyncAttendanceToFactorial($log->id))->handle();

        $log->refresh();
        $this->assertSame('failed', $log->sync_status);
        $this->assertStringContainsString('Regreso de pausa con el turno de la entrada (08:00) aún abierto: la salida a pausa no se registró', $log->sync_error);
        $this->assertStringNotContainsString('posible salida marcada como entrada', $log->sync_error);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    }

    private function openShift(int $id, int $employeeFactorialId, string $date, string $clockIn, ?string $inSource = null): array
    {
        return [
            'id'           => $id,
            'employee_id'  => $employeeFactorialId,
            'date'         => $date,
            'clock_in'     => '2000-01-01T' . $clockIn . '.000Z',
            'clock_out'    => null,
            'status'       => 'opened',
            'in_source'    => $inSource,
            'observations' => null,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * @return array{0: AttendanceLog, 1: FactorialEmployee}
     */
    private function makeLog(string $checkType, string $occurredAt = '2026-07-24 09:00:00'): array
    {
        $client = Client::create(['name' => 'Attendance Client', 'slug' => 'attendance-' . str()->random(8)]);

        $connection = FactorialConnection::create([
            'client_id'            => $client->id,
            'name'                 => 'Connection',
            'resource_owner_type'  => 'company',
            'access_token'         => 'test-token',
        ]);

        $source = $this->makeSource($client, $connection);

        $employee = FactorialEmployee::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id'            => 111,
            'company_id'              => $client->id,
            'full_name'               => 'Mapped Employee',
            'active'                  => true,
        ]);

        $log = AttendanceLog::create([
            'client_id'             => $client->id,
            'biometric_source_id'   => $source->id,
            'factorial_employee_id' => $employee->id,
            'employee_code'         => '111',
            'check_type'            => $checkType,
            'occurred_at'           => $occurredAt,
            'sync_status'           => 'resolved',
        ]);

        return [$log, $employee];
    }

    private function makeSource(Client $client, FactorialConnection $connection): BiometricSource
    {
        $provider = BiometricProvider::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'vendor'                  => 'zkteco',
            'status'                  => 'active',
        ]);

        return BiometricSource::create([
            'client_id'             => $client->id,
            'biometric_provider_id' => $provider->id,
            'name'                  => 'Attendance Device',
            'serial_number'         => 'ATT-' . str()->random(8),
            'status'                => 'active',
        ]);
    }
}
