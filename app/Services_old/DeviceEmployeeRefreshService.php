<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\DeviceSyncBatch;
use App\Models\User;

class DeviceEmployeeRefreshService
{
    public function __construct(
        private readonly DeviceSyncBatchService $batches,
        private readonly DeviceProtocolResolver $protocols,
    ) {}

    public function refresh(BiometricSource $source, ?User $creator = null): array
    {
        $cachedUsers = collect($source->device_users ?? [])->filter(fn($user) => isset($user['pin']));
        $reportedCount = $source->reported_user_count;
        $cacheIsReliable = $reportedCount === null || $cachedUsers->count() >= $reportedCount;
        $inventory = $cacheIsReliable ? $cachedUsers->keyBy(fn($user) => (string) $user['pin']) : collect();

        $assignments = $source->assignments()
            ->where('desired_state', 'present')
            ->with(['identity.factorialEmployee', 'syncItems'])
            ->get();

        $decisions = [];
        $unchanged = 0;
        $active = 0;

        foreach ($assignments as $assignment) {
            $identity = $assignment->identity;
            $systemName = $identity?->factorialEmployee?->full_name
                ?? $identity?->local_name
                ?? $assignment->name;
            $deviceName = $this->deviceName($systemName);
            $reportedName = data_get($inventory->get((string) $assignment->pin), 'name');

            $hasActiveOperation = in_array(
                $assignment->sync_status,
                ['planned', 'queued', 'sent', 'awaiting_verification'],
                true
            ) || $assignment->syncItems->contains(
                fn($item) => in_array($item->status, ['planned', 'queued', 'sent', 'acknowledged'], true)
            );

            if ($hasActiveOperation) {
                $active++;
                continue;
            }

            $systemNameChanged = $this->comparableName($assignment->name)
                !== $this->comparableName($deviceName);
            $hasFreshDetailedInventory = $this->protocols->inventoryMode($source) === 'detailed'
                && $source->device_users_fetched_at
                && (!$assignment->confirmed_at || $source->device_users_fetched_at->gte($assignment->confirmed_at));

            // An aggregate-only device cannot prove which individual PINs it has.
            // A confirmed assignment remains the source of truth unless its
            // canonical name changed. This prevents every refresh click from
            // re-sending the same users against an old inventory cache.
            if ($assignment->sync_status === 'confirmed' && !$systemNameChanged && !$hasFreshDetailedInventory) {
                $unchanged++;
                continue;
            }

            if ($reportedName !== null && $this->comparableName($reportedName) === $this->comparableName($deviceName)) {
                $unchanged++;
                continue;
            }

            $decisions[] = [
                'action' => $identity?->factorial_employee_id ? 'add_factorial' : 'add_local',
                'pin' => (string) $assignment->pin,
                'name' => $systemName,
                'factorial_employee_id' => $identity?->factorial_employee_id,
            ];
        }

        $batch = $decisions === []
            ? null
            : $this->batches->create($source, $decisions, $creator, 'refresh', 'device_refresh');

        return [
            'assigned' => $assignments->count(),
            'queued' => count($decisions),
            'unchanged' => $unchanged,
            'active' => $active,
            'batch' => $batch,
        ];
    }

    private function deviceName(mixed $name): string
    {
        return mb_substr(
            preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $name)) ?? '',
            0,
            24
        );
    }

    private function comparableName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        return mb_strtoupper($normalized);
    }
}
