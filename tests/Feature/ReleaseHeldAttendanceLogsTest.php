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
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ReleaseHeldAttendanceLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_liberar_despacha_en_orden_y_respeta_el_guardarrail(): void
    {
        Bus::fake();

        [$client, $source, $employee] = $this->empresa('eprecsa');
        [, , $ajeno]                  = $this->empresa('otra');

        $tarde    = $this->retenido($client, $source, $employee->id, '2026-09-10 18:00:00');
        $temprano = $this->retenido($client, $source, $employee->id, '2026-09-10 08:00:00');
        $cruzado  = $this->retenido($client, $source, $ajeno->id, '2026-09-10 09:00:00');

        $this->artisan('attendance:liberar-retenidos', ['--client' => $client->id])->assertExitCode(0);

        $dispatched = Bus::dispatched(SyncAttendanceToFactorial::class)->values();
        $this->assertSame([$temprano->id, $tarde->id], $dispatched->map->attendanceLogId->all());

        // 2 s de separación entre jobs.
        $delays = $dispatched->map(fn ($job) => $job->delay)->all();
        $this->assertEqualsWithDelta(2, $delays[1]->getTimestamp() - $delays[0]->getTimestamp(), 1);

        $this->assertSame('resolved', $temprano->fresh()->sync_status);
        $this->assertSame('resolved', $tarde->fresh()->sync_status);
        $this->assertSame('retenido', $cruzado->fresh()->sync_status);
    }

    public function test_liberar_se_niega_si_el_cliente_sigue_retenido(): void
    {
        Bus::fake();

        [$client, $source, $employee] = $this->empresa('eprecsa');
        $log = $this->retenido($client, $source, $employee->id, '2026-09-10 08:00:00');
        config(['attendance.sync_hold_clients' => [(int) $client->id]]);

        $this->artisan('attendance:liberar-retenidos', ['--client' => $client->id])->assertExitCode(1);

        Bus::assertNothingDispatched();
        $this->assertSame('retenido', $log->fresh()->sync_status);
    }

    public function test_dry_run_no_escribe(): void
    {
        Bus::fake();

        [$client, $source, $employee] = $this->empresa('eprecsa');
        $log = $this->retenido($client, $source, $employee->id, '2026-09-10 08:00:00');

        $this->artisan('attendance:liberar-retenidos', ['--client' => $client->id, '--dry-run' => true])->assertExitCode(0);

        Bus::assertNothingDispatched();
        $this->assertSame('retenido', $log->fresh()->sync_status);
    }

    /** @return array{0: Client, 1: BiometricSource, 2: FactorialEmployee} */
    private function empresa(string $slug): array
    {
        $client     = Client::create(['name' => ucfirst($slug), 'slug' => $slug . '-' . str()->random(6)]);
        $connection = FactorialConnection::create([
            'client_id' => $client->id, 'name' => 'Conexión', 'resource_owner_type' => 'company', 'access_token' => 'test-token',
        ]);
        $provider = BiometricProvider::create([
            'client_id' => $client->id, 'factorial_connection_id' => $connection->id, 'vendor' => 'zkteco', 'status' => 'active',
        ]);
        $source = BiometricSource::create([
            'client_id' => $client->id, 'biometric_provider_id' => $provider->id, 'name' => 'Equipo',
            'serial_number' => 'HOLD-' . str()->random(8), 'status' => 'active',
        ]);
        $employee = FactorialEmployee::create([
            'client_id' => $client->id, 'factorial_connection_id' => $connection->id, 'factorial_id' => random_int(100000, 999999),
            'company_id' => $client->id, 'full_name' => 'Empleado ' . $slug, 'active' => true,
        ]);

        return [$client, $source, $employee];
    }

    private function retenido(Client $client, BiometricSource $source, int $employeeId, string $at): AttendanceLog
    {
        return AttendanceLog::create([
            'client_id' => $client->id, 'biometric_source_id' => $source->id, 'factorial_employee_id' => $employeeId,
            'employee_code' => (string) random_int(1000, 9999), 'check_type' => 'check_in', 'occurred_at' => $at,
            'sync_status' => 'retenido', 'sync_note' => SyncAttendanceToFactorial::HOLD_NOTE,
        ]);
    }
}
