<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\FactorialEmployee;

class DeviceReconciliationService
{
    public function analyze(BiometricSource $source): array
    {
        $snapshot = $source->inventorySnapshots()
            ->with('users')
            ->latest('captured_at')
            ->first();

        $reported = $snapshot?->users?->keyBy(fn($user) => (string) $user->pin) ?? collect();

        $identities = BiometricUserSync::query()
            ->where('biometric_provider_id', $source->biometric_provider_id)
            ->with('factorialEmployee')
            ->get();

        $identitiesByPin = $identities->keyBy(fn($identity) => (string) $identity->external_employee_code);
        $mappedEmployeeIds = $identities->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id')
            ->map(fn($id) => (int) $id)
            ->flip();

        $employees = FactorialEmployee::query()
            ->where('client_id', $source->client_id)
            ->where('active', true)
            ->orderBy('full_name')
            ->get();

        $employeesByNormalizedName = $employees
            ->groupBy(fn($employee) => $this->normalizeName($employee->full_name));

        $rows = collect();

        foreach ($reported as $pin => $reportedUser) {
            $identity = $identitiesByPin->get($pin);

            if ($identity?->factorial_employee_id) {
                $rows->push([
                    'case' => 'matched_factorial',
                    'pin' => $pin,
                    'reported_name' => $reportedUser->name,
                    'identity_id' => $identity->id,
                    'factorial_employee_id' => $identity->factorial_employee_id,
                    'factorial_name' => $identity->factorialEmployee?->full_name,
                    'suggested_factorial_employee_id' => null,
                ]);
                continue;
            }

            if ($identity?->local_name) {
                $rows->push([
                    'case' => 'matched_local',
                    'pin' => $pin,
                    'reported_name' => $reportedUser->name,
                    'identity_id' => $identity->id,
                    'factorial_employee_id' => null,
                    'factorial_name' => null,
                    'suggested_factorial_employee_id' => null,
                ]);
                continue;
            }

            $matches = $employeesByNormalizedName->get($this->normalizeName($reportedUser->name), collect());
            $suggested = $matches->count() === 1 ? $matches->first() : null;

            $rows->push([
                'case' => $suggested ? 'device_only_suggested' : 'device_only',
                'pin' => $pin,
                'reported_name' => $reportedUser->name,
                'identity_id' => null,
                'factorial_employee_id' => null,
                'factorial_name' => null,
                'suggested_factorial_employee_id' => $suggested?->id,
                'suggested_factorial_name' => $suggested?->full_name,
            ]);
        }

        foreach ($employees as $employee) {
            if ($mappedEmployeeIds->has($employee->id)) {
                $identity = $identities->firstWhere('factorial_employee_id', $employee->id);
                $pin = (string) $identity->external_employee_code;

                if (!$reported->has($pin)) {
                    $rows->push([
                        'case' => 'factorial_mapped_missing_on_device',
                        'pin' => $pin,
                        'reported_name' => null,
                        'identity_id' => $identity->id,
                        'factorial_employee_id' => $employee->id,
                        'factorial_name' => $employee->full_name,
                        'suggested_factorial_employee_id' => null,
                    ]);
                }
                continue;
            }

            $rows->push([
                'case' => 'factorial_only',
                'pin' => null,
                'reported_name' => null,
                'identity_id' => null,
                'factorial_employee_id' => $employee->id,
                'factorial_name' => $employee->full_name,
                'suggested_factorial_employee_id' => null,
            ]);
        }

        return [
            'source_id' => $source->id,
            'snapshot_id' => $snapshot?->id,
            'captured_at' => $snapshot?->captured_at,
            'rows' => $rows->values()->all(),
            'summary' => $rows->countBy('case')->all(),
        ];
    }

    private function normalizeName(?string $name): string
    {
        return str($name ?? '')
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
