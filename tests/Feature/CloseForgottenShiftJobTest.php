<?php

namespace Tests\Feature;

use App\Jobs\CloseForgottenShiftJob;
use App\Models\AttendanceLog;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloseForgottenShiftJobTest extends TestCase
{
    use RefreshDatabase;

    private const SHIFTS_URL      = 'https://api.factorialhr.com/api/2026-04-01/resources/attendance/shifts';
    private const OPEN_SHIFTS_URL = 'https://api.factorialhr.com/api/2026-04-01/resources/attendance/open_shifts';

    public function test_closes_shift_that_is_still_open(): void
    {
        $employee = $this->makeEmployee();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [[
                    'id'          => 900,
                    'employee_id' => 555555,
                    'status'      => 'opened',
                ]]], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::SHIFTS_URL . '/900') {
                return Http::response(['id' => 900], 200);
            }

            return Http::response([], 404);
        });

        (new CloseForgottenShiftJob(
            factorialEmployeeId: $employee->id,
            shiftId: 900,
            closeAt: '2026-08-10 17:00:00',
            checkinOnly: true,
            reason: 'con_horario',
        ))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === self::SHIFTS_URL . '/900'
            && $request['clock_out'] === '17:00:00'
            && str_contains($request['observations'], 'No se registró salida'));

        // También debe quedar visible en attendance_logs, con la nota de rutina
        // (checkin_only=true) — no la de "revisar horas".
        $log = AttendanceLog::where('factorial_shift_id', 900)->first();
        $this->assertNotNull($log);
        $this->assertSame('check_out', $log->check_type);
        $this->assertSame('synced', $log->sync_status);
        $this->assertSame('2026-08-10 17:00:00', $log->occurred_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('solo entrada (rutina)', $log->sync_note);
        $this->assertSame('Cierre automático', $log->biometricSource->name);
    }

    public function test_records_review_needed_note_when_not_checkin_only(): void
    {
        $employee = $this->makeEmployee();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [['id' => 900, 'employee_id' => 555555, 'status' => 'opened']]], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::SHIFTS_URL . '/900') {
                return Http::response(['id' => 900], 200);
            }

            return Http::response([], 404);
        });

        (new CloseForgottenShiftJob(
            factorialEmployeeId: $employee->id,
            shiftId: 900,
            closeAt: '2026-08-10 17:00:00',
            checkinOnly: false,
            reason: 'sin_horario',
        ))->handle();

        $log = AttendanceLog::where('factorial_shift_id', 900)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('turno olvidado, revisar horas', $log->sync_note);
    }

    public function test_forces_review_note_for_descanso_confirmado_even_when_checkin_only(): void
    {
        $employee = $this->makeEmployee();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [['id' => 900, 'employee_id' => 555555, 'status' => 'opened']]], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::SHIFTS_URL . '/900') {
                return Http::response(['id' => 900], 200);
            }

            return Http::response([], 404);
        });

        // checkin_only=true normalmente da la nota de rutina — pero
        // 'descanso_confirmado' (estimated_times.source=work_schedule) es una
        // anomalía real y debe forzar la nota/estilo de revisión igual.
        (new CloseForgottenShiftJob(
            factorialEmployeeId: $employee->id,
            shiftId: 900,
            closeAt: '2026-08-10 17:00:00',
            checkinOnly: true,
            reason: 'descanso_confirmado',
        ))->handle();

        $log = AttendanceLog::where('factorial_shift_id', 900)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('día de descanso confirmado, revisar horas', $log->sync_note);
    }

    public function test_does_nothing_if_shift_already_closed(): void
    {
        $employee = $this->makeEmployee();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                // El turno ya no aparece como abierto — alguien marcó salida real
                // mientras el job esperaba en la cola.
                return Http::response(['data' => []], 200);
            }

            return Http::response([], 404);
        });

        (new CloseForgottenShiftJob(
            factorialEmployeeId: $employee->id,
            shiftId: 900,
            closeAt: '2026-08-10 17:00:00',
            checkinOnly: true,
            reason: 'con_horario',
        ))->handle();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_throws_when_factorial_does_not_confirm_the_close(): void
    {
        $employee = $this->makeEmployee();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [['id' => 900, 'employee_id' => 555555, 'status' => 'opened']]], 200);
            }

            if ($request->method() === 'PUT' && $request->url() === self::SHIFTS_URL . '/900') {
                // Respuesta sin ID confirmado
                return Http::response([], 200);
            }

            return Http::response([], 404);
        });

        $this->expectException(\RuntimeException::class);

        (new CloseForgottenShiftJob(
            factorialEmployeeId: $employee->id,
            shiftId: 900,
            closeAt: '2026-08-10 17:00:00',
            checkinOnly: true,
            reason: 'con_horario',
        ))->handle();
    }

    private function makeEmployee(): FactorialEmployee
    {
        $client = Client::create(['name' => 'Close Shift Client', 'slug' => 'close-shift-' . str()->random(8)]);

        $connection = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => 'Connection',
            'resource_owner_type' => 'company',
            'access_token'        => 'test-token',
        ]);

        return FactorialEmployee::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id'            => 555555,
            'company_id'              => $client->id,
            'full_name'               => 'Empleado De Prueba',
            'active'                  => true,
        ]);
    }
}
