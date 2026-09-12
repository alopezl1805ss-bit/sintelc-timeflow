<?php

namespace Tests\Feature;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Models\User;
use App\Services\AttendanceEmployeeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Botones del tablero (incidencia H01).
 *
 * «Descartar fallidos» hacía `delete()` físico de todos los `failed` de todos
 * los clientes: borraba justo la evidencia necesaria para auditar el cruce.
 * «Reintentar» disparaba el resolver global. Ahora descartar sólo marca, y
 * reintentar exige un cliente en contexto.
 */
class AttendanceStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AttendanceEmployeeGuard::flush();
    }

    /** @return array{client: Client, conn: FactorialConnection, provider: BiometricProvider, source: BiometricSource} */
    private function empresa(string $slug): array
    {
        $client = Client::create(['name' => strtoupper($slug), 'slug' => $slug]);

        $conn = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => "conn-{$slug}",
            'resource_owner_type' => 'company',
            'access_token'        => 'token-' . $slug,
        ]);

        $provider = BiometricProvider::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $conn->id,
            'vendor'                  => 'zkteco',
            'status'                  => 'active',
        ]);

        $source = BiometricSource::create([
            'client_id'             => $client->id,
            'biometric_provider_id' => $provider->id,
            'name'                  => "dev-{$slug}",
            'serial_number'         => 'SN-' . strtoupper($slug),
            'status'                => 'active',
        ]);

        return ['client' => $client, 'conn' => $conn, 'provider' => $provider, 'source' => $source];
    }

    private function empleado(array $empresa, int $factorialId, string $nombre): FactorialEmployee
    {
        return FactorialEmployee::create([
            'client_id'               => $empresa['client']->id,
            'factorial_connection_id' => $empresa['conn']->id,
            'factorial_id'            => $factorialId,
            'full_name'               => $nombre,
            'company_id'              => $factorialId,
            'active'                  => true,
            'attendable'              => true,
        ]);
    }

    private function marcaje(array $empresa, string $status, ?int $employeeId = null): AttendanceLog
    {
        return AttendanceLog::create([
            'client_id'             => $empresa['client']->id,
            'biometric_source_id'   => $empresa['source']->id,
            'factorial_employee_id' => $employeeId,
            'employee_code'         => '5',
            'check_type'            => 'check_in',
            'occurred_at'           => now()->subHours(3),
            'sync_status'            => $status,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'client_id' => null]);
    }

    public function test_descartar_no_borra_registros(): void
    {
        $a = $this->empresa('descartar');
        $empleado = $this->empleado($a, 55, 'Empleado Descartar');
        $log = $this->marcaje($a, 'failed', $empleado->id);

        $admin = $this->admin();
        $this->actingAs($admin);

        Volt::test('dashboard.attendance-stats')->call('dismissFailed');

        $log->refresh();
        $this->assertNotNull($log, 'El registro no debe borrarse');
        $this->assertSame('descartado', $log->sync_status);
        $this->assertStringContainsString($admin->name, (string) $log->sync_note);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_reintentar_solo_afecta_al_cliente_visible(): void
    {
        Queue::fake();

        $a = $this->empresa('reintento-a');
        $b = $this->empresa('reintento-b');

        $empleadoA = $this->empleado($a, 101, 'Empleado A');
        $empleadoB = $this->empleado($b, 202, 'Empleado B');

        $logA = $this->marcaje($a, 'pending', $empleadoA->id);
        $logB = $this->marcaje($b, 'pending', $empleadoB->id);

        $this->actingAs($this->admin());

        Volt::test('dashboard.attendance-stats', ['clientFilterId' => $a['client']->id])
            ->call('retryPending');

        $this->assertSame('resolved', $logA->refresh()->sync_status);
        $this->assertSame('pending', $logB->refresh()->sync_status, 'El cliente no visible no debe tocarse');

        Queue::assertPushed(SyncAttendanceToFactorial::class, 1);
        Queue::assertPushed(
            SyncAttendanceToFactorial::class,
            fn ($job) => $job->attendanceLogId === $logA->id
        );
    }

    public function test_reintento_global_desactivado_para_admin(): void
    {
        Queue::fake();

        $a = $this->empresa('reintento-global');
        $empleado = $this->empleado($a, 303, 'Empleado Global');
        $log = $this->marcaje($a, 'pending', $empleado->id);

        // Un mapeo válido: si el reintento global siguiera activo, resolvería.
        BiometricUserSync::create([
            'client_id'              => $a['client']->id,
            'biometric_provider_id'  => $a['provider']->id,
            'factorial_employee_id'  => $empleado->id,
            'external_employee_code' => '5',
            'sync_status'            => 'synced',
        ]);

        $this->actingAs($this->admin());

        Volt::test('dashboard.attendance-stats')
            ->assertSet('clientFilterId', null)
            ->assertSee('ver incidencia H01')
            ->call('retryPending');

        $this->assertSame('pending', $log->refresh()->sync_status);
        Queue::assertNothingPushed();
    }

    public function test_descartar_conserva_el_motivo_del_fallo_y_la_nota_previa(): void
    {
        $a = $this->empresa('conserva');
        $empleado = $this->empleado($a, 56, 'Empleado Conserva');
        $log = $this->marcaje($a, 'failed', $empleado->id);
        AttendanceLog::whereKey($log->id)->update([
            'sync_error' => 'Sin turno abierto para sobreescribir',
            'sync_note'  => 'overwrite (api)',
        ]);

        $admin = $this->admin();
        $this->actingAs($admin);
        Volt::test('dashboard.attendance-stats')->call('dismissFailed');

        $log->refresh();
        $this->assertSame('descartado', $log->sync_status);
        $this->assertSame('Sin turno abierto para sobreescribir', $log->sync_error, 'El motivo del fallo es evidencia: no se borra');
        $this->assertStringContainsString($admin->name, (string) $log->sync_note);
        $this->assertStringContainsString('overwrite (api)', (string) $log->sync_note);
        $this->assertLessThanOrEqual(255, mb_strlen((string) $log->sync_note));
    }
}
