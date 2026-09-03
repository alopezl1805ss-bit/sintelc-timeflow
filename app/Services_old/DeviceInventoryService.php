<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\DeviceInventorySnapshot;
use Illuminate\Support\Facades\DB;

class DeviceInventoryService
{
    public function capture(
        BiometricSource $source,
        array $users,
        string $origin = 'device',
        array $metadata = []
    ): DeviceInventorySnapshot {
        $capturedAt = now();
        $normalized = collect($users)
            ->map(fn(array $user) => $this->normalizeUser($user))
            ->filter(fn(array $user) => $user['pin'] !== '')
            ->keyBy('pin')
            ->values();

        $snapshot = DB::transaction(function () use ($source, $origin, $metadata, $capturedAt, $normalized) {
            $snapshot = DeviceInventorySnapshot::create([
                'biometric_source_id' => $source->id,
                'origin' => $origin,
                'status' => 'complete',
                'user_count' => $normalized->count(),
                'captured_at' => $capturedAt,
                'metadata' => $metadata ?: null,
            ]);

            if ($normalized->isNotEmpty()) {
                $snapshot->users()->insert($normalized->map(fn(array $user) => [
                    'device_inventory_snapshot_id' => $snapshot->id,
                    'biometric_source_id' => $source->id,
                    'pin' => $user['pin'],
                    'name' => $user['name'] ?: null,
                    'card' => $user['card'] ?: null,
                    'privilege' => $user['privilege'] ?: null,
                    'protocol' => $user['protocol'] ?: null,
                    'raw_data' => json_encode($user['raw_data'], JSON_UNESCAPED_UNICODE),
                    'created_at' => $capturedAt,
                    'updated_at' => $capturedAt,
                ])->all());
            }

            $updates = ['last_inventory_at' => $capturedAt];
            if (in_array($source->onboarding_status, ['assigned', 'querying_users', 'awaiting_users'], true)) {
                $updates['onboarding_status'] = 'needs_review';
                $updates['onboarding_error'] = null;
            }
            $source->update($updates);

            return $snapshot->load('users');
        });

        app(DeviceAssignmentVerificationService::class)->verify($source->fresh(), $snapshot);
        app(DeviceAssignmentMaterializer::class)->materialize($source->fresh(), $normalized);

        return $snapshot->fresh('users');
    }

    private function normalizeUser(array $user): array
    {
        $clean = fn(mixed $value, int $max): string => mb_substr(
            preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $value)) ?? '',
            0,
            $max
        );

        return [
            'pin' => $clean($user['pin'] ?? '', 64),
            'name' => $clean($user['name'] ?? '', 255),
            'card' => $clean($user['card'] ?? '', 255),
            'privilege' => $clean($user['privilege'] ?? '', 50),
            'protocol' => $clean($user['protocol'] ?? '', 50),
            'raw_data' => $user,
        ];
    }
}
