<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\DeviceSyncBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeviceAggregateVerificationService
{
    public function __construct(
        private readonly DeviceProtocolResolver $protocols,
        private readonly DeviceSyncBatchService $batches,
    ) {}

    public function verify(BiometricSource $source, int $reportedUserCount, Carbon $reportedAt): void
    {
        if ($this->protocols->inventoryMode($source) !== 'aggregate_info') {
            return;
        }

        $batchIds = $source->syncItems()
            ->where('status', 'acknowledged')
            ->pluck('device_sync_batch_id')
            ->unique();

        foreach (DeviceSyncBatch::whereKey($batchIds)->get() as $batch) {
            $items = $batch->items()
                ->where('biometric_source_id', $source->id)
                ->where('status', 'acknowledged')
                ->with(['assignment.identity', 'commands'])
                ->get();

            if ($items->isEmpty()) {
                continue;
            }

            $lastAcknowledgedAt = $items->flatMap->commands->max('acknowledged_at');
            if (!$lastAcknowledgedAt || $reportedAt->lte($lastAcknowledgedAt)) {
                continue;
            }

            $baseline = data_get($batch->options, 'verification_baseline_user_count');
            if ($baseline === null) {
                $baseline = $source->inventorySnapshots()
                    ->where('captured_at', '<=', $batch->created_at)
                    ->latest('captured_at')
                    ->value('user_count');
            }
            if ($baseline === null) {
                continue;
            }

            // SET USER is an upsert: sending an existing PIN updates it and must
            // not increase the expected aggregate user count.
            $desiredUserCount = $source->assignments()
                ->where('desired_state', 'present')
                ->distinct()
                ->count('pin');
            $expected = max((int) $baseline, $desiredUserCount);
            if ($reportedUserCount < $expected) {
                continue;
            }

            DB::transaction(function () use ($items) {
                foreach ($items as $item) {
                    $item->update([
                        'status' => 'confirmed',
                        'verification_method' => 'device_info_count',
                        'error' => null,
                    ]);
                    $item->assignment?->update([
                        'sync_status' => 'confirmed',
                        'verification_method' => 'device_info_count',
                        'confirmed_at' => now(),
                        'last_error' => null,
                    ]);
                    $item->assignment?->identity?->update([
                        'sync_status' => 'synced',
                        'sync_error' => null,
                        'synced_at' => now(),
                    ]);
                }
            });

            $this->batches->refreshBatch($batch);
        }

        $hasPending = $source->assignments()
            ->whereIn('sync_status', ['planned', 'queued', 'sent', 'awaiting_verification'])
            ->exists();

        if (!$hasPending) {
            $source->update([
                'onboarding_status' => 'ready',
                'onboarding_completed_at' => now(),
                'onboarding_error' => null,
            ]);
        }
    }
}
