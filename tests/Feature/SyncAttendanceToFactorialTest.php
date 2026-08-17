<?php

namespace Tests\Feature;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
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

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
                return Http::response([
                    'data' => [[
                        'id'           => 900,
                        'employee_id'  => $employee->factorial_id,
                        'date'         => '2026-07-24',
                        'clock_in'     => '09:00:00',
                        'clock_out'    => null,
                        'in_source'    => null,
                        'observations' => null,
                    ]],
                ], 200);
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
                return Http::response([
                    'data' => [[
                        'id'           => 900,
                        'employee_id'  => $employee->factorial_id,
                        'date'         => '2026-07-24',
                        'clock_in'     => '09:00:00',
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
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::SHIFTS_URL)) {
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
