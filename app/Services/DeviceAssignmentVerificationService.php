<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\DeviceInventorySnapshot;
use App\Models\DeviceSyncBatch;
use App\Models\DeviceUserAssignment;
use Illuminate\Support\Facades\DB;

class DeviceAssignmentVerificationService
{
    public function __construct(private readonly DeviceSyncBatchService $batches) {}

    public function verify(BiometricSource $source, DeviceInventorySnapshot $snapshot): void
    {
        $reportedPins = $snapshot->users()->pluck('pin')->all();

        $batchIds = DB::transaction(function () use ($source, $reportedPins) {
            $batchIds = [];

            if ($reportedPins !== []) {
                $batchIds = array_merge($batchIds, $this->confirmPresent($source, $reportedPins));
            }

            // Bajas (Alcance 1.3): confirmamos que un PIN con desired_state
            // 'absent' ya no aparece en el inventario reportado. A diferencia
            // del alta, esto sí corre incluso con inventario vacío — un
            // dispositivo que reporta cero usuarios confirma cualquier baja
            // pendiente igual de bien.
            $batchIds = array_merge($batchIds, $this->confirmAbsent($source, $reportedPins));

            return array_values(array_unique($batchIds));
        });

        foreach (DeviceSyncBatch::whereKey($batchIds)->get() as $batch) {
            $this->batches->refreshBatch($batch);
        }

        $hasPending = DeviceUserAssignment::query()
            ->where('biometric_source_id', $source->id)
            ->whereIn('sync_status', ['planned', 'queued', 'sent', 'awaiting_verification'])
            ->exists();

        if (!$hasPending && $batchIds !== []) {
            $source->update([
                'onboarding_status' => 'ready',
                'onboarding_completed_at' => now(),
                'onboarding_error' => null,
            ]);
        }
    }

    /** @return array<int> IDs de DeviceSyncBatch afectados */
    private function confirmPresent(BiometricSource $source, array $reportedPins): array
    {
        $assignments = DeviceUserAssignment::query()
            ->where('biometric_source_id', $source->id)
            ->where('desired_state', 'present')
            ->whereIn('sync_status', ['planned', 'queued', 'sent', 'awaiting_verification'])
            ->whereIn('pin', $reportedPins)
            ->lockForUpdate()
            ->get();

        $batchIds = [];
        foreach ($assignments as $assignment) {
            $assignment->update([
                'sync_status' => 'confirmed',
                'confirmed_at' => now(),
                'last_error' => null,
            ]);
            $assignment->identity?->update([
                'sync_status' => 'synced',
                'sync_error' => null,
                'synced_at' => now(),
            ]);

            $batchIds = array_merge($batchIds, $this->confirmItems($assignment));
        }

        return $batchIds;
    }

    /**
     * Simétrico a confirmPresent(): un PIN con desired_state='absent' que ya
     * no aparece en el inventario reportado confirma que el dispositivo
     * ejecutó el delete_user. No toca la identidad (BiometricUserSync) —
     * archivarla es una decisión explícita del usuario (Alcance 1.3/1.6),
     * no algo que se infiera solo de una baja en un dispositivo.
     *
     * @return array<int> IDs de DeviceSyncBatch afectados
     */
    private function confirmAbsent(BiometricSource $source, array $reportedPins): array
    {
        $assignments = DeviceUserAssignment::query()
            ->where('biometric_source_id', $source->id)
            ->where('desired_state', 'absent')
            ->whereIn('sync_status', ['planned', 'queued', 'sent', 'awaiting_verification'])
            ->whereNotIn('pin', $reportedPins)
            ->lockForUpdate()
            ->get();

        $batchIds = [];
        foreach ($assignments as $assignment) {
            $assignment->update([
                'sync_status' => 'confirmed',
                'confirmed_at' => now(),
                'last_error' => null,
            ]);

            $batchIds = array_merge($batchIds, $this->confirmItems($assignment));
        }

        return $batchIds;
    }

    /** @return array<int> IDs de DeviceSyncBatch afectados */
    private function confirmItems(DeviceUserAssignment $assignment): array
    {
        $batchIds = [];
        $items = $assignment->syncItems()
            ->whereIn('status', ['planned', 'queued', 'sent', 'acknowledged'])
            ->get();

        foreach ($items as $item) {
            $item->update(['status' => 'confirmed', 'error' => null]);
            $batchIds[] = $item->device_sync_batch_id;
        }

        return $batchIds;
    }
}
