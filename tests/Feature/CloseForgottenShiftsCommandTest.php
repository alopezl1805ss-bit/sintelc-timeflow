<?php

namespace Tests\Feature;

use App\Jobs\CloseForgottenShiftJob;
use App\Models\Client;
use App\Models\ClientAttendanceConfig;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloseForgottenShiftsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const OPEN_SHIFTS_URL     = 'https://api.factorialhr.com/api/2026-04-01/resources/attendance/open_shifts';
    private const ESTIMATED_TIMES_URL = 'https://api.factorialhr.com/api/2026-04-01/resources/attendance/estimated_times';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dispatches_close_job_when_shift_exceeds_horas_asignadas_mas_margen(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();

        [$employee] = $this->makeClientWithEmployee(autoClose: true, checkinOnly: true);

        $this->fakeOpenShiftsAndEstimatedTimes(
            employeeFactorialId: $employee->factorial_id,
            date: '2026-08-16',
            clockIn: '08:00:00',
            expectedMinutes: 480, // 8h
            shiftId: 900,
        );

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        // 08:00 + 8h esperadas = 16:00 (hora grabada, sin margen)
        // 16:00 + 4h de margen = 20:00 umbral. now=21:00 >= 20:00 → debe cerrar.
        Bus::assertDispatched(CloseForgottenShiftJob::class, function (CloseForgottenShiftJob $job) use ($employee) {
            return $job->factorialEmployeeId === $employee->id
                && $job->shiftId === 900
                && $job->closeAt === '2026-08-16 16:00:00'
                && $job->checkinOnly === true
                && $job->reason === 'con_horario';
        });
    }

    public function test_does_not_dispatch_while_still_within_margin(): void
    {
        Carbon::setTestNow('2026-08-16 19:00:00'); // antes del umbral de las 20:00
        Bus::fake();

        [$employee] = $this->makeClientWithEmployee(autoClose: true, checkinOnly: true);

        $this->fakeOpenShiftsAndEstimatedTimes(
            employeeFactorialId: $employee->factorial_id,
            date: '2026-08-16',
            clockIn: '08:00:00',
            expectedMinutes: 480,
            shiftId: 900,
        );

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        Bus::assertNotDispatched(CloseForgottenShiftJob::class);
    }

    public function test_uses_default_8h_when_no_estimated_minutes(): void
    {
        Carbon::setTestNow('2026-08-16 19:00:00');
        Bus::fake();

        [$employee] = $this->makeClientWithEmployee(autoClose: true, checkinOnly: false);

        // Sin horario (expected_minutes = 0): entrada 06:00 + 8h default = 14:00,
        // + 4h de margen = 18:00 umbral. now=19:00 >= 18:00 → debe cerrar.
        $this->fakeOpenShiftsAndEstimatedTimes(
            employeeFactorialId: $employee->factorial_id,
            date: '2026-08-16',
            clockIn: '06:00:00',
            expectedMinutes: 0,
            shiftId: 901,
        );

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        Bus::assertDispatched(CloseForgottenShiftJob::class, function (CloseForgottenShiftJob $job) {
            return $job->closeAt === '2026-08-16 14:00:00'
                && $job->reason === 'sin_horario'
                && $job->checkinOnly === false;
        });
    }

    public function test_marks_descanso_confirmado_when_source_is_work_schedule_even_for_checkin_only(): void
    {
        Carbon::setTestNow('2026-08-16 19:00:00');
        Bus::fake();

        // checkin_only=true normalmente es "rutina" — pero un turno abierto
        // en un día confirmado como descanso (source=work_schedule) es una
        // anomalía real y debe marcarse para revisión igual, ver
        // CloseForgottenShiftJob::handle().
        [$employee] = $this->makeClientWithEmployee(autoClose: true, checkinOnly: true);

        $this->fakeOpenShiftsAndEstimatedTimes(
            employeeFactorialId: $employee->factorial_id,
            date: '2026-08-16',
            clockIn: '06:00:00',
            expectedMinutes: 0,
            shiftId: 902,
            source: 'work_schedule',
        );

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        Bus::assertDispatched(CloseForgottenShiftJob::class, function (CloseForgottenShiftJob $job) {
            return $job->closeAt === '2026-08-16 14:00:00'
                && $job->reason === 'descanso_confirmado'
                && $job->checkinOnly === true;
        });
    }

    public function test_skips_clients_without_auto_close_enabled(): void
    {
        Bus::fake();

        $this->makeClientWithEmployee(autoClose: false, checkinOnly: false);

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        Bus::assertNotDispatched(CloseForgottenShiftJob::class);
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** @return array{0: FactorialEmployee} */
    private function makeClientWithEmployee(bool $autoClose, bool $checkinOnly): array
    {
        $client = Client::create(['name' => 'Auto Close Client', 'slug' => 'auto-close-' . str()->random(8)]);

        ClientAttendanceConfig::create([
            'client_id'                   => $client->id,
            'checkin_id'                  => '0',
            'checkout_id'                 => '1',
            'checkin_only'                => $checkinOnly,
            'auto_close_forgotten_shifts' => $autoClose,
        ]);

        $connection = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => 'Connection',
            'resource_owner_type' => 'company',
            'access_token'        => 'test-token',
        ]);

        $employee = FactorialEmployee::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $connection->id,
            'factorial_id'            => random_int(100000, 999999),
            'company_id'              => $client->id,
            'full_name'               => 'Empleado De Prueba',
            'active'                  => true,
        ]);

        return [$employee];
    }

    private function fakeOpenShiftsAndEstimatedTimes(
        int $employeeFactorialId,
        string $date,
        string $clockIn,
        int $expectedMinutes,
        int $shiftId,
        ?string $source = null,
    ): void {
        Http::fake(function (Request $request) use ($employeeFactorialId, $date, $clockIn, $expectedMinutes, $shiftId, $source) {
            if ($request->method() === 'GET' && str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [[
                    'id'          => $shiftId,
                    'employee_id' => $employeeFactorialId,
                    'date'        => $date,
                    'clock_in'    => '2000-01-01T' . $clockIn . '.000Z',
                    'clock_out'   => null,
                    'status'      => 'opened',
                ]]], 200);
            }

            if ($request->method() === 'GET' && str_starts_with($request->url(), self::ESTIMATED_TIMES_URL)) {
                return Http::response(['data' => [[
                    'date'             => $date,
                    'source'           => $source,
                    'employee_id'      => $employeeFactorialId,
                    'expected_minutes' => $expectedMinutes,
                ]]], 200);
            }

            return Http::response([], 404);
        });
    }
}
