<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\DeviceCommand;
use App\Models\DeviceSyncBatch;
use App\Models\DeviceSyncItem;
use App\Models\DeviceUserAssignment;
use App\Models\FactorialEmployee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceSyncBatchService
{
    public function create(
        BiometricSource $source,
        array $decisions,
        ?User $creator = null,
        string $type = 'onboarding',
        string $origin = 'manual'
    ): DeviceSyncBatch {
        abort_unless($source->client_id && $source->biometric_provider_id, 422);

        return DB::transaction(function () use ($source, $decisions, $creator, $type, $origin) {
            $source = BiometricSource::query()->lockForUpdate()->findOrFail($source->id);
            $baselineUserCount = $source->reported_user_count;
            if ($baselineUserCount === null) {
                $baselineUserCount = $source->inventorySnapshots()->latest('captured_at')->value('user_count');
            }
            $batch = DeviceSyncBatch::create([
                'uuid' => (string) Str::uuid(),
                'client_id' => $source->client_id,
                'created_by' => $creator?->id,
                'type' => $type,
                'origin' => $origin,
                'status' => 'preparing',
                'options' => [
                    'verification_baseline_user_count' => $baselineUserCount,
                    'protocol_profile' => app(DeviceProtocolResolver::class)->resolve($source),
                ],
                'started_at' => now(),
            ]);

            $nextSequence = (int) DeviceCommand::where('biometric_source_id', $source->id)
                ->max('command_seq') + 1;

            foreach ($decisions as $index => $decision) {
                $normalized = $this->validateDecision($source, $decision, $index);
                $item = $this->applyDecision($batch, $source, $normalized, $nextSequence);

                if ($item->status === 'queued') {
                    $nextSequence++;
                }
            }

            $desiredUserCount = $source->assignments()
                ->where('desired_state', 'present')
                ->distinct()
                ->count('pin');
            $batch->update(['options' => array_merge($batch->options ?? [], [
                'verification_expected_user_count' => $baselineUserCount !== null
                    ? max((int) $baselineUserCount, $desiredUserCount)
                    : null,
            ])]);

            $this->refreshBatch($batch);

            $source->update([
                'onboarding_status' => match (true) {
                    $batch->pending_items > 0 => 'applying_changes',
                    $batch->failed_items > 0 => 'ready_with_issues',
                    default => 'ready',
                },
                'onboarding_completed_at' => $batch->pending_items === 0 ? now() : null,
                'onboarding_error' => null,
            ]);

            return $batch->fresh('items');
        });
    }

    public function refreshBatch(DeviceSyncBatch $batch): DeviceSyncBatch
    {
        $counts = $batch->items()
            ->selectRaw('status, COUNT(*) total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $counts->sum();
        $confirmed = (int) ($counts['confirmed'] ?? 0) + (int) ($counts['ignored'] ?? 0);
        $failed = (int) ($counts['failed'] ?? 0) + (int) ($counts['conflict'] ?? 0);
        $pending = max(0, $total - $confirmed - $failed);

        $status = match (true) {
            $total === 0 => 'empty',
            $pending > 0 => 'processing',
            $failed === 0 => 'completed',
            $confirmed === 0 => 'failed',
            default => 'completed_with_issues',
        };

        $batch->update([
            'status' => $status,
            'total_items' => $total,
            'confirmed_items' => $confirmed,
            'failed_items' => $failed,
            'pending_items' => $pending,
            'completed_at' => $pending === 0 ? now() : null,
        ]);

        return $batch->fresh();
    }

    private function applyDecision(
        DeviceSyncBatch $batch,
        BiometricSource $source,
        array $decision,
        int $sequence
    ): DeviceSyncItem {
        if ($decision['action'] === 'ignore') {
            return DeviceSyncItem::create($this->itemData($batch, $source, $decision, [
                'status' => 'ignored',
            ]));
        }

        $identity = $this->resolveIdentity($source, $decision);
        $reported = in_array($decision['action'], ['map_factorial', 'keep_local'], true);

        $assignment = DeviceUserAssignment::updateOrCreate(
            ['biometric_source_id' => $source->id, 'pin' => $decision['pin']],
            [
                'client_id' => $source->client_id,
                'biometric_user_sync_id' => $identity->id,
                'factorial_employee_id' => $identity->factorial_employee_id,
                'name' => $decision['name'],
                'desired_state' => 'present',
                'sync_status' => $reported ? 'confirmed' : 'planned',
                'confirmed_at' => $reported ? now() : null,
                'last_error' => null,
            ]
        );

        $item = DeviceSyncItem::create($this->itemData($batch, $source, $decision, [
            'device_user_assignment_id' => $assignment->id,
            'factorial_employee_id' => $identity->factorial_employee_id,
            'status' => $reported ? 'confirmed' : 'planned',
        ]));

        if ($reported) {
            return $item;
        }

        $activeItem = DeviceSyncItem::query()
            ->where('device_user_assignment_id', $assignment->id)
            ->whereKeyNot($item->id)
            ->whereIn('status', ['queued', 'sent', 'acknowledged'])
            ->exists();

        if ($activeItem) {
            $item->update([
                'status' => 'conflict',
                'error' => 'Ya existe una operación activa para este PIN y dispositivo.',
            ]);
            return $item;
        }

        $command = DeviceCommand::create([
            'biometric_source_id' => $source->id,
            'device_sync_item_id' => $item->id,
            'command_seq' => $sequence,
            'command_type' => 'set_user',
            'idempotency_key' => "device-user:{$batch->uuid}:{$source->id}:{$decision['pin']}",
            'payload' => $this->userPayload($decision['pin'], $decision['name']),
            'status' => 'pending',
        ]);

        $item->update(['status' => 'queued']);
        $assignment->update(['sync_status' => 'queued']);
        $identity->update(['sync_status' => 'pending', 'last_attempt_at' => now()]);

        return $item->fresh();
    }

    private function resolveIdentity(BiometricSource $source, array $decision): BiometricUserSync
    {
        $pinConflict = BiometricUserSync::query()
            ->where('biometric_provider_id', $source->biometric_provider_id)
            ->where('external_employee_code', $decision['pin'])
            ->first();

        if ($decision['syncs_with_factorial']) {
            $employee = FactorialEmployee::query()
                ->where('client_id', $source->client_id)
                ->whereKey($decision['factorial_employee_id'])
                ->first();

            if (!$employee) {
                throw ValidationException::withMessages([
                    'decisions' => 'El empleado de Factorial no pertenece a este cliente o ya no existe.',
                ]);
            }

            if ($pinConflict && (int) $pinConflict->factorial_employee_id !== (int) $employee->id) {
                throw ValidationException::withMessages([
                    'decisions' => "El PIN {$decision['pin']} ya pertenece a otra persona.",
                ]);
            }

            return BiometricUserSync::updateOrCreate(
                [
                    'biometric_provider_id' => $source->biometric_provider_id,
                    'factorial_employee_id' => $employee->id,
                ],
                [
                    'client_id' => $source->client_id,
                    'external_employee_code' => $decision['pin'],
                    'local_name' => null,
                    'sync_status' => 'pending',
                    'last_attempt_at' => now(),
                ]
            );
        }

        if ($pinConflict && $pinConflict->factorial_employee_id) {
            throw ValidationException::withMessages([
                'decisions' => "El PIN {$decision['pin']} ya está mapeado con Factorial.",
            ]);
        }

        return BiometricUserSync::updateOrCreate(
            [
                'biometric_provider_id' => $source->biometric_provider_id,
                'external_employee_code' => $decision['pin'],
            ],
            [
                'client_id' => $source->client_id,
                'factorial_employee_id' => null,
                'local_name' => $decision['system_name'],
                'sync_status' => 'pending',
                'last_attempt_at' => now(),
            ]
        );
    }

    private function validateDecision(BiometricSource $source, array $decision, int $index): array
    {
        $action = (string) ($decision['action'] ?? '');
        $allowed = ['ignore', 'map_factorial', 'keep_local', 'add_factorial', 'add_local'];

        if (!in_array($action, $allowed, true)) {
            throw ValidationException::withMessages([
                "decisions.{$index}.action" => 'La acción seleccionada no es válida.',
            ]);
        }

        $pin = trim((string) ($decision['pin'] ?? ''));
        $systemName = $this->cleanSystemName($decision['name'] ?? '');
        $name = $this->cleanName($systemName);
        if (!preg_match('/^\d{1,14}$/', $pin)) {
            throw ValidationException::withMessages([
                "decisions.{$index}.pin" => 'El PIN debe contener entre 1 y 14 dígitos.',
            ]);
        }
        if ($action !== 'ignore' && $name === '') {
            throw ValidationException::withMessages([
                "decisions.{$index}.name" => 'El nombre es obligatorio.',
            ]);
        }

        $syncsWithFactorial = in_array($action, ['map_factorial', 'add_factorial'], true);
        $employeeId = isset($decision['factorial_employee_id'])
            ? (int) $decision['factorial_employee_id']
            : null;

        if ($syncsWithFactorial && !$employeeId) {
            throw ValidationException::withMessages([
                "decisions.{$index}.factorial_employee_id" => 'Selecciona un empleado de Factorial.',
            ]);
        }

        return [
            'action' => $action,
            'pin' => $pin,
            'name' => $name,
            'system_name' => $systemName,
            'syncs_with_factorial' => $syncsWithFactorial,
            'factorial_employee_id' => $employeeId,
        ];
    }

    private function itemData(
        DeviceSyncBatch $batch,
        BiometricSource $source,
        array $decision,
        array $extra = []
    ): array {
        return array_merge([
            'device_sync_batch_id' => $batch->id,
            'biometric_source_id' => $source->id,
            'action' => $decision['action'],
            'pin' => $decision['pin'],
            'name' => $decision['name'] ?: 'Ignorado',
            'syncs_with_factorial' => $decision['syncs_with_factorial'],
            'status' => 'planned',
        ], $extra);
    }

    private function cleanName(mixed $name): string
    {
        return mb_substr(
            preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $name)) ?? '',
            0,
            24
        );
    }

    private function cleanSystemName(mixed $name): string
    {
        return mb_substr(
            preg_replace('/[\x00-\x1F\x7F]/u', '', trim((string) $name)) ?? '',
            0,
            255
        );
    }

    private function userPayload(string $pin, string $name): string
    {
        return "DATA UPDATE USERINFO PIN={$pin}\tName={$name}\tPassword=\tPrivilege=0\tGroup=1";
    }
}
