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
        if ($reportedPins === []) {
            return;
        }

        $batchIds = DB::transaction(function () use ($source, $reportedPins) {
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

                $items = $assignment->syncItems()
                    ->whereIn('status', ['planned', 'queued', 'sent', 'acknowledged'])
                    ->get();
                foreach ($items as $item) {
                    $item->update(['status' => 'confirmed', 'error' => null]);
                    $batchIds[] = $item->device_sync_batch_id;
                }
            }

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
}
