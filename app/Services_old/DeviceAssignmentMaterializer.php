<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\DeviceUserAssignment;
use Illuminate\Support\Facades\DB;

class DeviceAssignmentMaterializer
{
    public function materialize(BiometricSource $source, iterable $users): int
    {
        if (!$source->client_id || !$source->biometric_provider_id) {
            return 0;
        }

        $reported = collect($users)
            ->map(fn($user) => [
                'pin' => trim((string) data_get($user, 'pin')),
                'name' => trim((string) data_get($user, 'name')),
            ])
            ->filter(fn(array $user) => $user['pin'] !== '')
            ->keyBy('pin');

        if ($reported->isEmpty()) {
            return 0;
        }

        $identities = BiometricUserSync::query()
            ->where('client_id', $source->client_id)
            ->where('biometric_provider_id', $source->biometric_provider_id)
            ->whereIn('external_employee_code', $reported->keys())
            ->get()
            ->keyBy(fn(BiometricUserSync $identity) => (string) $identity->external_employee_code);

        return DB::transaction(function () use ($source, $reported, $identities) {
            $created = 0;

            foreach ($reported as $pin => $user) {
                $identity = $identities->get((string) $pin);
                if (!$identity) {
                    continue;
                }

                $assignment = DeviceUserAssignment::firstOrNew([
                    'biometric_source_id' => $source->id,
                    'pin' => (string) $pin,
                ]);

                if (!$assignment->exists) {
                    $created++;
                }

                // A reported inventory is evidence that the user is present.
                // This only materializes state; it never creates commands/batches.
                $assignment->fill([
                    'client_id' => $source->client_id,
                    'biometric_user_sync_id' => $identity->id,
                    'factorial_employee_id' => $identity->factorial_employee_id,
                    'name' => $user['name'] ?: ($identity->local_name ?: (string) $pin),
                    'desired_state' => 'present',
                    'sync_status' => 'confirmed',
                    'verification_method' => 'device_inventory',
                    'confirmed_at' => now(),
                    'last_error' => null,
                ])->save();
            }

            return $created;
        });
    }

    public function materializeCached(?int $clientId = null): array
    {
        $sources = BiometricSource::query()
            ->when($clientId, fn($query) => $query->where('client_id', $clientId))
            ->whereNotNull('client_id')
            ->whereNotNull('biometric_provider_id')
            ->get();

        $created = 0;
        foreach ($sources as $source) {
            $created += $this->materialize($source, $source->device_users ?? []);
        }

        return [
            'sources' => $sources->count(),
            'created' => $created,
        ];
    }
}
