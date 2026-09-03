<?php

namespace App\Jobs;

use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Models\FactorialLocation;
use App\Services\FactorialService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncFactorialConnection implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 120;

    public function __construct(
        public readonly int $connectionId
    ) {}

    public function handle(): void
    {
        $connection = FactorialConnection::with('client')->find($this->connectionId);

        if (!$connection || empty($connection->access_token)) {
            Log::warning('SyncFactorialConnection: conexión no encontrada o sin token', [
                'connection_id' => $this->connectionId,
            ]);
            $this->storeResult(['ok' => false, 'error' => 'Conexión no encontrada o sin token.']);
            return;
        }

        try {
            $service = new FactorialService($connection);

            // ── Empleados con paginación cursor-based ─────────────────
            $allEmployees = [];
            $limit        = 100;
            $pageNum      = 0;
            $total        = null;
            $cursor       = null;
            $seenIds      = [];

            do {
                $pageNum++;
                $this->storeResult(['ok' => null, 'progress' => "Página {$pageNum} · " . count($allEmployees) . " empleados descargados…"]);

                $params = ['limit' => $limit];
                if ($cursor) {
                    $params['after_id'] = $cursor;
                }

                $response = $service->getEmployees($params);
                $page     = $response['data'] ?? [];
                $meta     = $response['meta'] ?? [];

                if (empty($page)) break;

                // Obtener total en primera página
                if ($pageNum === 1) {
                    $total = (int) ($meta['total'] ?? 0);
                }

                // Dedup: parar si todos los IDs de esta página ya los vimos (safety net)
                $newIds  = array_column($page, 'id');
                $overlap = array_intersect($newIds, $seenIds);
                if (count($overlap) === count($newIds)) {
                    Log::warning('SyncFactorialConnection: dedup break (API devolvió IDs repetidos)', [
                        'connection_id' => $this->connectionId,
                        'pageNum'       => $pageNum,
                        'cursor'        => $cursor,
                    ]);
                    break;
                }

                $allEmployees = array_merge($allEmployees, $page);
                $seenIds      = array_merge($seenIds, $newIds);

                // Avanzar cursor
                $hasNext = (bool) ($meta['has_next_page'] ?? false);
                $cursor  = $meta['end_cursor'] ?? null;

                // Parar si ya tenemos el total según meta
                if ($total > 0 && count($allEmployees) >= $total) break;

                // Cap duro: máximo 20 páginas (2000 empleados)
                if ($pageNum >= 20) break;

            } while ($hasNext && $cursor);

            $empCount = 0;
            $now      = now()->toDateTimeString();
            $rows     = [];

            foreach ($allEmployees as $employee) {
                if (empty($employee['id'])) continue;

                $rows[] = [
                    'factorial_connection_id' => $connection->id,
                    'factorial_id'            => (int) $employee['id'],
                    'client_id'               => $connection->client_id,
                    'first_name'              => $employee['first_name'] ?? null,
                    'last_name'               => $employee['last_name'] ?? null,
                    'full_name'               => $employee['full_name'] ?? null,
                    'email'                   => $employee['email'] ?? null,
                    'login_email'             => $employee['login_email'] ?? null,
                    'company_id'              => isset($employee['company_id']) ? (int) $employee['company_id'] : null,
                    'company_identifier'      => $employee['company_identifier'] ?? null,
                    'location_id'             => isset($employee['location_id']) ? (int) $employee['location_id'] : null,
                    'active'                  => (bool) ($employee['active'] ?? false),
                    'attendable'              => (bool) ($employee['attendable'] ?? false),
                    'is_terminating'          => (bool) ($employee['is_terminating'] ?? false),
                    'terminated_on'           => isset($employee['terminated_on'])
                        ? Carbon::parse($employee['terminated_on'])->toDateTimeString()
                        : null,
                    'factorial_created_at'    => isset($employee['created_at'])
                        ? Carbon::parse($employee['created_at'])->toDateTimeString()
                        : null,
                    'factorial_updated_at'    => isset($employee['updated_at'])
                        ? Carbon::parse($employee['updated_at'])->toDateTimeString()
                        : null,
                    'raw_payload'             => json_encode($employee),
                    'created_at'              => $now,
                    'updated_at'              => $now,
                ];
            }

            if (!empty($rows)) {
                FactorialEmployee::upsert(
                    $rows,
                    ['factorial_connection_id', 'factorial_id'],
                    [
                        'client_id', 'first_name', 'last_name', 'full_name',
                        'email', 'login_email', 'company_id', 'company_identifier',
                        'location_id', 'active', 'attendable', 'is_terminating',
                        'terminated_on', 'factorial_created_at', 'factorial_updated_at',
                        'raw_payload', 'updated_at',
                    ]
                );
                $empCount = count($rows);
            }

            // ── Ubicaciones (opcional, puede fallar por permisos) ─────
            $locCount = 0;
            $locError = null;

            try {
                $locResponse = $service->getLocations();
                $locations   = $locResponse['data'] ?? $locResponse;

                if (is_array($locations)) {
                    foreach ($locations as $location) {
                        if (empty($location['id'])) continue;

                        FactorialLocation::updateOrCreate(
                            [
                                'factorial_connection_id' => $connection->id,
                                'factorial_location_id'   => (int) $location['id'],
                            ],
                            [
                                'client_id'            => $connection->client_id,
                                'factorial_company_id' => isset($location['company_id']) ? (int) $location['company_id'] : null,
                                'name'                 => $location['name'] ?? "Ubicación {$location['id']}",
                            ]
                        );
                        $locCount++;
                    }
                }
            } catch (\Throwable $le) {
                $locError = $le->getMessage();
                Log::warning('SyncFactorialConnection: no se pudieron obtener ubicaciones', [
                    'connection_id' => $connection->id,
                    'message'       => $locError,
                ]);
            }

            Log::info('SyncFactorialConnection: completado', [
                'connection_id' => $connection->id,
                'employees'     => $empCount,
                'locations'     => $locCount,
            ]);

            $this->storeResult([
                'ok'        => true,
                'employees' => $empCount,
                'locations' => $locCount,
                'loc_error' => $locError,
            ]);

        } catch (\Throwable $e) {
            Log::error('SyncFactorialConnection: error', [
                'connection_id' => $this->connectionId,
                'message'       => $e->getMessage(),
            ]);

            $this->storeResult(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function storeResult(array $result): void
    {
        Cache::put("factorial_sync_result:{$this->connectionId}", $result, now()->addMinutes(10));
    }
}
