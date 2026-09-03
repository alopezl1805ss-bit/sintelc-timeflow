<?php

namespace Tests\Feature;

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\FactorialConnection;
use App\Models\User;
use App\Services\DeviceCommandLifecycleService;
use App\Services\DeviceInventoryService;
use App\Services\DeviceSyncBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * UI de baja de empleados (Alcance 1.3) en employee-sync-manager: pestaña
 * Archivados, baja masiva desde selección, baja por dispositivo específico
 * desde "Administrar", y reactivación.
 */
class EmployeeOffboardingUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_tab_renders_empty_state(): void
    {
        [$client, $user] = $this->makeClient();
        $this->actingAs($user);

        Livewire::test('employees.employee-sync-manager')
            ->set('client_id', $client->id)
            ->set('tab', 'archived')
            ->assertSee('No hay empleados archivados.');
    }

    public function test_bulk_archive_removes_from_devices_and_hides_from_active_list(): void
    {
        [$client, $user, $source] = $this->makeClient();
        $this->addAndConfirm($source, '700', 'Empleado Siete');
        $this->actingAs($user);

        $component = Livewire::test('employees.employee-sync-manager')
            ->set('client_id', $client->id)
            ->set('tab', 'biometric')
            ->assertSee('Empleado Siete')
            ->call('setSelectAll', ['700'], true)
            ->call('archiveSelected');

        // Nota: no se usa assertDontSee('700') — "700" es substring de clases
        // Tailwind como text-gray-700 presentes en cualquier render de la
        // página, así que sería un falso positivo. El nombre es una señal
        // limpia de que la fila realmente desapareció del listado activo.
        $component
            ->assertSet('selected', [])
            ->assertDontSee('Empleado Siete');

        $this->assertDatabaseHas('biometric_user_syncs', [
            'external_employee_code' => '700',
            'status' => 'archived',
        ]);
        $this->assertDatabaseHas('device_commands', [
            'biometric_source_id' => $source->id,
            'command_type' => 'delete_user',
        ]);

        // Aparece en la pestaña Archivados.
        $component->set('tab', 'archived')->assertSee('Empleado Siete');
    }

    public function test_reactivate_brings_the_employee_back_to_the_active_list(): void
    {
        [$client, $user, $source] = $this->makeClient();
        $identity = $this->addAndConfirm($source, '800', 'Empleado Ocho');
        app(\App\Services\EmployeeOffboardingService::class)->archive($identity);
        $this->actingAs($user);

        Livewire::test('employees.employee-sync-manager')
            ->set('client_id', $client->id)
            ->set('tab', 'archived')
            ->assertSee('Empleado Ocho')
            ->call('reactivateIdentity', '800')
            ->set('tab', 'biometric')
            ->assertSee('Empleado Ocho');

        $this->assertDatabaseHas('biometric_user_syncs', [
            'external_employee_code' => '800',
            'status' => 'active',
        ]);
    }

    public function test_removing_a_single_device_from_manage_modal_no_longer_shows_the_blocked_message(): void
    {
        [$client, $user, $source] = $this->makeClient();
        $this->addAndConfirm($source, '900', 'Empleado Nueve');
        $this->actingAs($user);

        Livewire::test('employees.employee-sync-manager')
            ->set('client_id', $client->id)
            ->call('openDeviceAssignments', '900')
            ->assertSet('manageSourceIds', [$source->id])
            ->set('manageSourceIds', [])
            ->call('saveDeviceAssignments')
            ->assertSet('manageError', null)
            ->assertSet('showDeviceAssignmentModal', false);

        $this->assertDatabaseHas('device_user_assignments', [
            'biometric_source_id' => $source->id,
            'pin' => '900',
            'desired_state' => 'absent',
        ]);
        $this->assertDatabaseHas('device_commands', [
            'biometric_source_id' => $source->id,
            'command_type' => 'delete_user',
        ]);
        // La identidad NO se archiva por una baja parcial de dispositivo.
        $this->assertDatabaseHas('biometric_user_syncs', [
            'external_employee_code' => '900',
            'status' => 'active',
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function addAndConfirm(BiometricSource $source, string $pin, string $name): BiometricUserSync
    {
        app(DeviceSyncBatchService::class)->create($source, [[
            'action' => 'add_local',
            'pin'    => $pin,
            'name'   => $name,
        ]]);

        $command = DeviceCommand::where('biometric_source_id', $source->id)
            ->where('command_type', 'set_user')
            ->latest('id')
            ->firstOrFail();
        $command->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        app(DeviceCommandLifecycleService::class)->markAcknowledged($command->fresh(), true, 'Return=0');

        app(DeviceInventoryService::class)->capture($source->fresh(), [['pin' => $pin, 'name' => $name]]);

        return BiometricUserSync::where('external_employee_code', $pin)->firstOrFail();
    }

    /** @return array{0: Client, 1: User, 2: BiometricSource} */
    private function makeClient(): array
    {
        $client = Client::create(['name' => 'Offboard Client', 'slug' => 'offboard-' . str()->random(8), 'status' => 'active']);
        $user = User::factory()->create(['role' => 'client', 'client_id' => $client->id]);

        $connection = FactorialConnection::create([
            'client_id' => $client->id, 'name' => 'Connection', 'resource_owner_type' => 'company',
        ]);
        $provider = BiometricProvider::create([
            'client_id' => $client->id, 'factorial_connection_id' => $connection->id,
            'vendor' => 'zkteco', 'status' => 'active',
        ]);
        $source = BiometricSource::create([
            'client_id' => $client->id, 'biometric_provider_id' => $provider->id,
            'name' => 'Offboard Device', 'serial_number' => 'OFF-' . str()->random(8), 'status' => 'active',
        ]);

        return [$client, $user, $source];
    }
}
