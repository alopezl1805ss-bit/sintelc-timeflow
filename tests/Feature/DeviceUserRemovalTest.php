<?php

namespace Tests\Feature;

use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\DeviceCommand;
use App\Models\DeviceUserAssignment;
use App\Models\FactorialConnection;
use App\Services\DeviceCommandLifecycleService;
use App\Services\DeviceInventoryService;
use App\Services\DeviceSyncBatchService;
use App\Services\EmployeeOffboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Baja de empleados (Alcance 1.3): por dispositivo específico, varios, o
 * todos (con archivado). Cubre el pipeline completo — createRemoval(),
 * confirmación simétrica en DeviceAssignmentVerificationService, y la
 * orquestación de EmployeeOffboardingService.
 */
class DeviceUserRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_removal_queues_a_delete_user_command_for_a_present_assignment(): void
    {
        [, , $source] = $this->makeSource();
        $this->addAndConfirm($source, '100', 'Empleado Uno');

        $batch = app(DeviceSyncBatchService::class)->createRemoval($source, ['100']);

        $this->assertSame('removal', $batch->type);
        $this->assertDatabaseHas('device_commands', [
            'biometric_source_id' => $source->id,
            'command_type'        => 'delete_user',
            'status'               => 'pending',
        ]);
        $command = DeviceCommand::where('command_type', 'delete_user')->firstOrFail();
        $this->assertSame('DATA DELETE USERINFO PIN=100', $command->payload);
        $this->assertDatabaseHas('device_user_assignments', [
            'biometric_source_id' => $source->id,
            'pin'                  => '100',
            'desired_state'        => 'absent',
            'sync_status'          => 'queued',
        ]);
    }

    public function test_removal_is_a_no_op_for_a_pin_not_currently_assigned(): void
    {
        [, , $source] = $this->makeSource();

        $batch = app(DeviceSyncBatchService::class)->createRemoval($source, ['999']);

        $this->assertSame(0, DeviceCommand::where('command_type', 'delete_user')->count());
        $this->assertSame(0, $batch->total_items);
    }

    public function test_absence_from_a_fresh_inventory_confirms_the_removal(): void
    {
        [, , $source] = $this->makeSource();
        $this->addAndConfirm($source, '200', 'Empleado Dos');

        app(DeviceSyncBatchService::class)->createRemoval($source, ['200']);
        $command = DeviceCommand::where('command_type', 'delete_user')->firstOrFail();
        $command->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        app(DeviceCommandLifecycleService::class)->markAcknowledged($command->fresh(), true, 'Return=0');

        // Todavía no confirmado: markAcknowledged no toca sync_status para
        // command_type=delete_user (solo tiene lógica genérica de item), la
        // confirmación real viene del inventario.
        $this->assertNotSame('confirmed', DeviceUserAssignment::where('pin', '200')->value('sync_status'));

        // El dispositivo reporta su inventario SIN el PIN 200 — confirma la baja.
        app(DeviceInventoryService::class)->capture($source->fresh(), [
            ['pin' => '201', 'name' => 'Otro Empleado'],
        ]);

        $assignment = DeviceUserAssignment::where('pin', '200')->firstOrFail();
        $this->assertSame('confirmed', $assignment->sync_status);
        $this->assertSame('absent', $assignment->desired_state);
        $this->assertDatabaseHas('device_sync_items', ['pin' => '200', 'status' => 'confirmed']);
    }

    public function test_pin_still_reported_does_not_confirm_the_removal(): void
    {
        [, , $source] = $this->makeSource();
        $this->addAndConfirm($source, '300', 'Empleado Tres');

        app(DeviceSyncBatchService::class)->createRemoval($source, ['300']);
        $command = DeviceCommand::where('command_type', 'delete_user')->firstOrFail();
        $command->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        app(DeviceCommandLifecycleService::class)->markAcknowledged($command->fresh(), true, 'Return=0');

        // El dispositivo TODAVÍA reporta el PIN 300 (ej. no ha procesado el
        // comando aún, o el comando fue rechazado silenciosamente) — no debe
        // confirmarse la baja.
        app(DeviceInventoryService::class)->capture($source->fresh(), [
            ['pin' => '300', 'name' => 'Empleado Tres'],
        ]);

        $assignment = DeviceUserAssignment::where('pin', '300')->firstOrFail();
        $this->assertNotSame('confirmed', $assignment->sync_status);
        $this->assertSame('absent', $assignment->desired_state);
    }

    public function test_remove_from_sources_leaves_the_identity_active(): void
    {
        [, , $source] = $this->makeSource();
        $identity = $this->addAndConfirm($source, '400', 'Empleado Cuatro');

        app(EmployeeOffboardingService::class)->removeFromSources($identity, [$source->id]);

        $this->assertSame('active', $identity->fresh()->status);
        $this->assertDatabaseHas('device_user_assignments', [
            'pin' => '400', 'desired_state' => 'absent',
        ]);
    }

    public function test_archive_removes_from_every_present_source_and_archives_the_identity(): void
    {
        [$client, $provider] = $this->makeSource();
        $sourceA = BiometricSource::create([
            'client_id' => $client->id, 'biometric_provider_id' => $provider->id,
            'name' => 'Device A', 'serial_number' => 'A-' . str()->random(6), 'status' => 'active',
        ]);
        $sourceB = BiometricSource::create([
            'client_id' => $client->id, 'biometric_provider_id' => $provider->id,
            'name' => 'Device B', 'serial_number' => 'B-' . str()->random(6), 'status' => 'active',
        ]);

        $identity = $this->addAndConfirm($sourceA, '500', 'Empleado Cinco');
        $this->addAndConfirm($sourceB, '500', 'Empleado Cinco', $identity);

        app(EmployeeOffboardingService::class)->archive($identity);

        $identity->refresh();
        $this->assertSame('archived', $identity->status);
        $this->assertNotNull($identity->archived_at);
        $this->assertSame(2, DeviceCommand::where('command_type', 'delete_user')->count());
        $this->assertDatabaseHas('device_user_assignments', ['biometric_source_id' => $sourceA->id, 'desired_state' => 'absent']);
        $this->assertDatabaseHas('device_user_assignments', ['biometric_source_id' => $sourceB->id, 'desired_state' => 'absent']);
    }

    public function test_reactivate_resets_status_without_touching_devices(): void
    {
        [, , $source] = $this->makeSource();
        $identity = $this->addAndConfirm($source, '600', 'Empleado Seis');
        app(EmployeeOffboardingService::class)->archive($identity);
        $setUserCommandsBeforeReactivate = DeviceCommand::where('command_type', 'set_user')->count();

        app(EmployeeOffboardingService::class)->reactivate($identity->fresh());

        $identity->refresh();
        $this->assertSame('active', $identity->status);
        $this->assertNull($identity->archived_at);
        // Reactivar no debe generar ningún comando set_user nuevo por sí solo.
        $this->assertSame($setUserCommandsBeforeReactivate, DeviceCommand::where('command_type', 'set_user')->count());
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function addAndConfirm(BiometricSource $source, string $pin, string $name, ?BiometricUserSync $existingIdentity = null): BiometricUserSync
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

        // capture() ya dispara verify() + materialize() internamente.
        app(DeviceInventoryService::class)->capture($source->fresh(), [['pin' => $pin, 'name' => $name]]);

        return BiometricUserSync::where('external_employee_code', $pin)->firstOrFail();
    }

    private function makeSource(): array
    {
        $client = Client::create(['name' => 'Removal Client', 'slug' => 'removal-' . str()->random(8)]);
        $connection = FactorialConnection::create([
            'client_id' => $client->id, 'name' => 'Connection', 'resource_owner_type' => 'company',
        ]);
        $provider = BiometricProvider::create([
            'client_id' => $client->id, 'factorial_connection_id' => $connection->id,
            'vendor' => 'zkteco', 'status' => 'active',
        ]);
        $source = BiometricSource::create([
            'client_id' => $client->id, 'biometric_provider_id' => $provider->id,
            'name' => 'Removal Device', 'serial_number' => 'REM-' . str()->random(8), 'status' => 'active',
        ]);

        return [$client, $provider, $source, $connection];
    }
}
