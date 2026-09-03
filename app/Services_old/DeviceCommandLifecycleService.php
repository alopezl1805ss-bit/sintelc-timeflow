<?php

namespace App\Services;

use App\Models\DeviceCommand;
use Illuminate\Support\Facades\DB;

class DeviceCommandLifecycleService
{
    public function __construct(
        private readonly DeviceSyncBatchService $batches,
        private readonly DeviceOnboardingService $onboarding,
    ) {}

    public function markSent(DeviceCommand $command): void
    {
        if (!$command->device_sync_item_id) {
            return;
        }

        DB::transaction(function () use ($command) {
            $item = $command->syncItem()->lockForUpdate()->first();
            if (!$item || $item->status !== 'queued') {
                return;
            }

            $item->update(['status' => 'sent']);
            $item->assignment?->update(['sync_status' => 'sent']);
            $this->batches->refreshBatch($item->batch);
        });
    }

    public function markAcknowledged(DeviceCommand $command, bool $successful, ?string $response = null): void
    {
        if (!$command->device_sync_item_id) {
            return;
        }

        $batch = DB::transaction(function () use ($command, $successful, $response) {
            $item = $command->syncItem()->lockForUpdate()->first();
            if (!$item) {
                return null;
            }

            if ($successful) {
                $item->update(['status' => 'acknowledged', 'error' => null]);
                $item->assignment?->update([
                    'sync_status' => 'awaiting_verification',
                    'last_error' => null,
                ]);
            } else {
                $error = $this->deviceError($response);
                $item->update(['status' => 'failed', 'error' => $error]);
                $item->assignment?->update(['sync_status' => 'failed', 'last_error' => $error]);
                $item->assignment?->identity?->update([
                    'sync_status' => 'failed',
                    'sync_error' => $error,
                ]);
                $command->source?->update([
                    'onboarding_status' => 'ready_with_issues',
                    'onboarding_completed_at' => now(),
                    'onboarding_error' => $error,
                ]);
            }

            return $this->batches->refreshBatch($item->batch);
        });

        if (!$successful || !$batch) {
            return;
        }

        // Cuando todos los comandos del batch han sido respondidos, pedir inventario actualizado
        $hasCommandsInFlight = $batch->items()
            ->whereIn('status', ['planned', 'queued', 'sent'])
            ->exists();

        if (!$hasCommandsInFlight) {
            $source = $command->source()->first();
            if ($source) {
                DB::transaction(function () use ($source) {
                    $source->update(['onboarding_status' => 'verifying']);
                    $this->onboarding->requestInventory($source);
                });
            }
        }
    }

    private function deviceError(?string $response): string
    {
        if ($response && preg_match('/Return=(-?\d+)/i', $response, $matches)) {
            return "El dispositivo rechazó el comando (código {$matches[1]}).";
        }

        return 'El dispositivo rechazó el comando.';
    }
}
