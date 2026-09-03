<?php

namespace App\Services;

use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\DeviceSyncBatch;
use App\Models\DeviceUserAssignment;
use App\Models\User;

/**
 * Baja de empleados (Alcance 1.3): individual — para un dispositivo
 * específico, varios, o todos los asociados — con estado activo/archivado
 * en FlowTime, sin eliminar el registro ni su histórico de asistencia.
 */
class EmployeeOffboardingService
{
    public function __construct(private readonly DeviceSyncBatchService $batches) {}

    /**
     * Da de baja al empleado de uno o varios dispositivos puntuales. La
     * identidad NO se archiva — sigue activa en FlowTime y en cualquier
     * otro dispositivo donde no se haya pedido la baja.
     *
     * @param  iterable<int>  $sourceIds
     * @return array<DeviceSyncBatch>
     */
    public function removeFromSources(BiometricUserSync $identity, iterable $sourceIds, ?User $creator = null): array
    {
        $assignmentsBySource = DeviceUserAssignment::query()
            ->where('biometric_user_sync_id', $identity->id)
            ->whereIn('biometric_source_id', $sourceIds)
            ->where('desired_state', 'present')
            ->get()
            ->groupBy('biometric_source_id');

        $batches = [];
        foreach ($assignmentsBySource as $sourceId => $assignments) {
            $source = BiometricSource::find($sourceId);
            if (!$source) {
                continue;
            }

            $batches[] = $this->batches->createRemoval(
                $source,
                $assignments->pluck('pin')->all(),
                $creator,
            );
        }

        return $batches;
    }

    /**
     * Baja general: pide la baja de TODOS los dispositivos donde el
     * empleado esté actualmente asignado, y archiva la identidad en
     * FlowTime. El histórico de asistencia y sincronización se conserva —
     * nunca se hace hard-delete del registro.
     *
     * @return array<DeviceSyncBatch>
     */
    public function archive(BiometricUserSync $identity, ?User $creator = null): array
    {
        $sourceIds = DeviceUserAssignment::query()
            ->where('biometric_user_sync_id', $identity->id)
            ->where('desired_state', 'present')
            ->pluck('biometric_source_id')
            ->unique();

        $batches = $this->removeFromSources($identity, $sourceIds, $creator);

        $identity->update(['status' => 'archived', 'archived_at' => now()]);

        return $batches;
    }

    /**
     * Reactiva la identidad archivada. No vuelve a enviarla a ningún
     * dispositivo automáticamente — eso pasa por el flujo normal de alta
     * (mapSelected / CSV), a propósito: reactivar no debe disparar comandos
     * a equipos sin que el usuario elija explícitamente a cuáles.
     */
    public function reactivate(BiometricUserSync $identity): void
    {
        $identity->update(['status' => 'active', 'archived_at' => null]);
    }
}
