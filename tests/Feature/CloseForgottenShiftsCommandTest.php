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
use Illuminate\Support\Facades\Log;
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

    // ── D1 «Nómina»: cierres falsos de 8 h (R01) ───────────────────

    public function test_pide_estimaciones_solo_de_turnos_abiertos_por_fecha(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();

        [$a, $b, $c] = $this->makeConnectionWithEmployees(3, checkinOnly: true);

        Http::fake(function (Request $request) use ($a, $b) {
            if (str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [
                    ['id' => 910, 'employee_id' => $a->factorial_id, 'date' => '2026-08-15', 'clock_in' => '2000-01-01T08:00:00.000Z', 'clock_out' => null, 'status' => 'opened'],
                    ['id' => 911, 'employee_id' => $b->factorial_id, 'date' => '2026-08-16', 'clock_in' => '2000-01-01T08:00:00.000Z', 'clock_out' => null, 'status' => 'opened'],
                ], 'meta' => ['has_next_page' => false]], 200);
            }

            if (str_starts_with($request->url(), self::ESTIMATED_TIMES_URL)) {
                $date = str_contains($request->url(), '2026-08-15') ? '2026-08-15' : '2026-08-16';
                $emp  = $date === '2026-08-15' ? $a : $b;

                return Http::response(['data' => [[
                    'date' => $date, 'source' => 'work_schedule', 'employee_id' => $emp->factorial_id, 'expected_minutes' => 480,
                ]], 'meta' => ['has_next_page' => false]], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        $estimateCalls = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_starts_with($r->url(), self::ESTIMATED_TIMES_URL))
            ->values();

        // Una llamada por fecha, rango de un solo día, sólo con quien tiene turno abierto ese día.
        $this->assertCount(2, $estimateCalls);
        $byDate = $estimateCalls->mapWithKeys(function (Request $r) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);
            return [$q['start_on'] => $q];
        });

        $this->assertSame('2026-08-15', $byDate['2026-08-15']['end_on']);
        $this->assertSame([(string) $a->factorial_id], $byDate['2026-08-15']['employee_ids']);
        $this->assertSame('2026-08-16', $byDate['2026-08-16']['end_on']);
        $this->assertSame([(string) $b->factorial_id], $byDate['2026-08-16']['employee_ids']);
        $this->assertFalse($estimateCalls->contains(fn (Request $r) => str_contains($r->url(), (string) $c->factorial_id)));

        Bus::assertDispatchedTimes(CloseForgottenShiftJob::class, 2);
        Bus::assertDispatched(CloseForgottenShiftJob::class, fn ($job) => $job->shiftId === 910 && $job->reason === 'con_horario');
    }

    public function test_si_la_respuesta_viene_truncada_no_cierra(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();

        [$a] = $this->makeConnectionWithEmployees(1, checkinOnly: true);

        Http::fake(function (Request $request) use ($a) {
            if (str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [
                    ['id' => 920, 'employee_id' => $a->factorial_id, 'date' => '2026-08-16', 'clock_in' => '2000-01-01T06:00:00.000Z', 'clock_out' => null, 'status' => 'opened'],
                ], 'meta' => ['has_next_page' => false]], 200);
            }

            // Página 1 de varias y la fila del empleado no vino en ella: antes
            // eso era «sin fila» ⇒ 8 h ⇒ cierre falso.
            if (str_starts_with($request->url(), self::ESTIMATED_TIMES_URL)) {
                return Http::response(['data' => [], 'meta' => ['has_next_page' => true, 'end_cursor' => 'abc', 'limit' => 100]], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);
        Bus::assertNotDispatched(CloseForgottenShiftJob::class);
    }

    public function test_si_open_shifts_viene_truncada_no_cierra_ese_lote(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();

        [$a] = $this->makeConnectionWithEmployees(1, checkinOnly: true);

        Http::fake(function (Request $request) use ($a) {
            if (str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [
                    ['id' => 921, 'employee_id' => $a->factorial_id, 'date' => '2026-08-16', 'clock_in' => '2000-01-01T06:00:00.000Z', 'clock_out' => null, 'status' => 'opened'],
                ], 'meta' => ['has_next_page' => true]], 200);
            }

            return Http::response(['data' => [], 'meta' => ['has_next_page' => false]], 200);
        });

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);
        Bus::assertNotDispatched(CloseForgottenShiftJob::class);
    }

    public function test_sin_fila_en_respuesta_completa_mantiene_comportamiento_documentado(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();

        // checkin_only (OUTLANDISH): no cerrar nunca reintroduciría el bloqueo
        // open_shift en su siguiente entrada.
        [$a] = $this->makeConnectionWithEmployees(1, checkinOnly: true);

        Http::fake(function (Request $request) use ($a) {
            if (str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                return Http::response(['data' => [
                    ['id' => 930, 'employee_id' => $a->factorial_id, 'date' => '2026-08-16', 'clock_in' => '2000-01-01T06:00:00.000Z', 'clock_out' => null, 'status' => 'opened'],
                ], 'meta' => ['has_next_page' => false]], 200);
            }

            if (str_starts_with($request->url(), self::ESTIMATED_TIMES_URL)) {
                return Http::response(['data' => [], 'meta' => ['has_next_page' => false]], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        Bus::assertDispatched(CloseForgottenShiftJob::class, fn (CloseForgottenShiftJob $job) => $job->shiftId === 930
            && $job->closeAt === '2026-08-16 14:00:00'
            && $job->reason === 'sin_horario'
            && $job->checkinOnly === true);
    }

    public function test_cierre_automatico_salta_cliente_retenido(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();

        [$employee] = $this->makeClientWithEmployee(autoClose: true, checkinOnly: true);
        config(['attendance.sync_hold_clients' => [(int) $employee->client_id]]);

        $this->fakeOpenShiftsAndEstimatedTimes(
            employeeFactorialId: $employee->factorial_id, date: '2026-08-16', clockIn: '08:00:00', expectedMinutes: 480, shiftId: 940,
        );

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        Bus::assertNotDispatched(CloseForgottenShiftJob::class);
        Http::assertNothingSent();
    }

    public function test_lote_truncado_se_parte_y_se_reintenta(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();

        $employees = $this->makeConnectionWithEmployees(20, checkinOnly: true);
        $calls     = [];

        Http::fake(function (Request $request) use (&$calls) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
            $ids = $q['employee_ids'] ?? [];

            if (str_starts_with($request->url(), self::OPEN_SHIFTS_URL)) {
                $calls[] = count($ids);

                // Más de 10 empleados por llamada ⇒ «página 1 de varias».
                return Http::response([
                    'data' => array_map(fn ($id) => ['id' => 5000 + (int) $id % 1000, 'employee_id' => (int) $id, 'date' => '2026-08-16', 'clock_in' => '2000-01-01T08:00:00.000Z', 'clock_out' => null, 'status' => 'opened'], array_slice($ids, 0, 5)),
                    'meta' => ['has_next_page' => count($ids) > 10],
                ], 200);
            }

            return Http::response(['data' => array_map(fn ($id) => ['date' => '2026-08-16', 'source' => 'work_schedule', 'employee_id' => (int) $id, 'expected_minutes' => 480], $ids), 'meta' => ['has_next_page' => false]], 200);
        });

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        // 20 → truncado → 10 + 10, cada mitad completa (5 turnos abiertos cada una).
        $this->assertSame([20, 10, 10], $calls);
        Bus::assertDispatchedTimes(CloseForgottenShiftJob::class, 10);
    }

    public function test_lote_truncado_al_piso_registra_critical_y_no_cierra(): void
    {
        Carbon::setTestNow('2026-08-16 21:00:00');
        Bus::fake();
        Log::spy();

        $this->makeConnectionWithEmployees(20, checkinOnly: true);

        Http::fake(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
            $ids = $q['employee_ids'] ?? [];

            return Http::response([
                'data' => array_map(fn ($id) => ['id' => 6000 + (int) $id % 1000, 'employee_id' => (int) $id, 'date' => '2026-08-16', 'clock_in' => '2000-01-01T08:00:00.000Z', 'clock_out' => null, 'status' => 'opened'], $ids),
                'meta' => ['has_next_page' => true],
            ], 200);
        });

        $this->artisan('attendance:close-forgotten-shifts')->assertExitCode(0);

        Bus::assertNotDispatched(CloseForgottenShiftJob::class);
        Log::shouldHaveReceived('critical')->withArgs(fn ($msg, $ctx) => str_contains($msg, 'open_shifts sigue truncado') && $ctx['empleados'] === 10)->twice();
    }

    /** @return list<FactorialEmployee> */
    private function makeConnectionWithEmployees(int $count, bool $checkinOnly): array
    {
        [$first] = $this->makeClientWithEmployee(autoClose: true, checkinOnly: $checkinOnly);
        $employees = [$first];

        for ($i = 1; $i < $count; $i++) {
            $employees[] = FactorialEmployee::create([
                'client_id'               => $first->client_id,
                'factorial_connection_id' => $first->factorial_connection_id,
                'factorial_id'            => $first->factorial_id + $i,
                'company_id'              => $first->client_id,
                'full_name'               => "Empleado {$i}",
                'active'                  => true,
            ]);
        }

        return $employees;
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
