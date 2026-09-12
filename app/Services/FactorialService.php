<?php

namespace App\Services;

use App\Models\FactorialConnection;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class FactorialService
{
    public function __construct(
        protected FactorialConnection $connection
    ) {}

    protected function baseUrl(): string
    {
        return rtrim(config('services.factorial.base_url'), '/');
    }

    protected function accessToken(): string
    {
        if (empty($this->connection->access_token)) {
            throw new RuntimeException('La conexión de Factorial no tiene access token.');
        }

        return $this->connection->access_token;
    }

    protected function request(string $method, string $uri, array $options = []): Response
    {
        $url      = $this->baseUrl() . '/' . ltrim($uri, '/');
        $response = $this->doRequest($method, $url, $options, $this->accessToken());

        // Si la respuesta es 401, intentar refrescar el token y reintentar una vez
        if ($response->status() === 401) {
            $this->refreshAccessToken();
            $response = $this->doRequest($method, $url, $options, $this->accessToken());
        }

        return $response->throw();
    }

    private function doRequest(string $method, string $url, array $options, string $token): Response
    {
        // R12 (mínimo): sin connectTimeout un host que no contesta retenía al
        // worker 30 s por llamada. El tipado y el circuit breaker van en la sombra.
        $request = Http::withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20);

        return match (strtolower($method)) {
            'get'    => $request->get($url, $options['query'] ?? []),
            'post'   => $request->post($url, $options['json'] ?? []),
            'put'    => $request->put($url, $options['json'] ?? []),
            'patch'  => $request->patch($url, $options['json'] ?? []),
            'delete' => $request->delete($url, $options['json'] ?? []),
            default  => throw new RuntimeException("Método HTTP no soportado: {$method}"),
        };
    }

    /**
     * Refresca el access_token usando el refresh_token.
     * Usa un Cache lock por conexión para que solo un worker refresque
     * a la vez — los demás esperan y reutilizan el token nuevo.
     */
    protected function refreshAccessToken(): void
    {
        $lockKey = "factorial_token_refresh:{$this->connection->id}";
        $lock    = Cache::lock($lockKey, 30); // máximo 30s esperando

        // Intentar adquirir el lock; si otro worker lo tiene, esperar hasta 25s
        $lock->block(25);

        try {
            // Recargar la conexión: puede que otro worker ya refrescó el token
            $this->connection->refresh();

            if (empty($this->connection->refresh_token)) {
                throw new RuntimeException(
                    "La conexión #{$this->connection->id} no tiene refresh_token. Reconecta el OAuth manualmente."
                );
            }

            $client = $this->connection->client;

            if (! $client || empty($client->oauth_client_id) || empty($client->oauth_client_secret)) {
                throw new RuntimeException(
                    "La conexión #{$this->connection->id} no tiene client_id/secret configurados en el cliente."
                );
            }

            $response = Http::asForm()->connectTimeout(5)->timeout(20)->post(
                $this->baseUrl() . '/oauth/token',
                [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $this->connection->refresh_token,
                    'client_id'     => $client->oauth_client_id,
                    'client_secret' => $client->oauth_client_secret,
                ]
            );

            if ($response->failed()) {
                throw new RuntimeException(
                    "Refresh token fallido para conexión #{$this->connection->id}: " . $response->body()
                );
            }

            $data = $response->json();

            $this->connection->update([
                'access_token'  => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? $this->connection->refresh_token,
                'expires_in'    => $data['expires_in'] ?? null,
                'expires_at'    => isset($data['expires_in'])
                    ? Carbon::now()->addSeconds((int) $data['expires_in'])
                    : null,
            ]);

            // Recargar para que accessToken() devuelva el nuevo valor
            $this->connection->refresh();

            Log::info('FactorialService: token refrescado', [
                'connection_id' => $this->connection->id,
            ]);

        } finally {
            $lock->release();
        }
    }

    // ── Métodos públicos ──────────────────────────────────────────────

    public function getEmployees(array $query = []): array
    {
        $defaultQuery = [
            'only_active'   => 'true',
            'only_managers' => 'false',
            'limit'         => 100,
        ];

        return $this->request(
            'get',
            '/api/2026-04-01/resources/employees/employees',
            ['query' => array_merge($defaultQuery, $query)]
        )->json();
    }

    public function getCompany(): array
    {
        return $this->request('get', '/api/2026-04-01/resources/companies/companies')->json()[0] ?? [];
    }

    public function getLocations(array $query = []): array
    {
        return $this->request(
            'get',
            '/api/2026-04-01/resources/locations/locations',
            ['query' => $query]
        )->json();
    }

    public function getBreakConfigurations(array $query = []): array
    {
        return $this->request(
            'get',
            '/api/2026-04-01/resources/attendance/break_configurations',
            ['query' => $query]
        )->json();
    }

    /**
     * Construye un query string donde los arrays se codifican como campo[]=valor
     * (Rails-style), en vez de campo[0]=valor (el default de http_build_query de PHP).
     * La API de Factorial no acepta el segundo formato — devuelve errores internos
     * confusos como "undefined method 'map' for an instance of String" o
     * "Could not coerce value (...) of type (Array) to desired type (Integer)".
     *
     * Pasamos el resultado como opción 'query' de tipo string (no array) a Guzzle:
     * así lo procesa con withQuery(), que codifica [] a %5B%5D correctamente.
     * Si en cambio pasáramos la URL ya construida, Guzzle podría double-encodear
     * los %5B%5D a %255B%255D.
     */
    private function buildQuery(array $q): string
    {
        $parts = [];
        foreach ($q as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = rawurlencode($key) . '[]=' . rawurlencode((string) $item);
                }
            } else {
                $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
            }
        }
        return implode('&', $parts);
    }

    public function getShifts(array $query = []): array
    {
        $allShifts = [];
        $offset    = 0;
        $limit     = 100;
        $pageNum   = 0;
        $total     = null;

        do {
            $pageNum++;
            $pagedQuery = array_merge($query, ['offset' => $offset, 'limit' => $limit]);

            $response = $this->request(
                'get',
                '/api/2026-04-01/resources/attendance/shifts',
                ['query' => $this->buildQuery($pagedQuery)]
            )->json();

            $page  = $response['data'] ?? $response;
            $meta  = $response['meta'] ?? [];

            if ($total === null) {
                $total = $meta['total_count'] ?? $meta['total'] ?? $meta['count'] ?? null;
            }

            if (empty($page) || !is_array($page)) break;

            $allShifts = array_merge($allShifts, $page);
            $offset   += $limit;

            if ($total !== null && count($allShifts) >= (int) $total) break;
            if ($pageNum >= 20) break; // cap: 2000 turnos máximo

        } while (count($page) === $limit);

        return $allShifts;
    }

    public function clockIn(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts/clock_in',
            ['json' => $payload]
        )->json();
    }

    public function clockOut(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts/clock_out',
            ['json' => $payload]
        )->json();
    }

    public function toggleClock(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts/toggle_clock',
            ['json' => $payload]
        )->json();
    }

    public function createShift(array $payload): array
    {
        return $this->request(
            'post',
            '/api/2026-04-01/resources/attendance/shifts',
            ['json' => $payload]
        )->json();
    }

    public function updateShift(int $shiftId, array $payload): array
    {
        return $this->request(
            'put',
            "/api/2026-04-01/resources/attendance/shifts/{$shiftId}",
            ['json' => $payload]
        )->json();
    }

    public function deleteShift(int $shiftId): bool
    {
        $this->request(
            'delete',
            "/api/2026-04-01/resources/attendance/shifts/{$shiftId}"
        );

        return true;
    }

    // Máximo de employee_ids por llamada — mandar todos de un jalón produce
    // un query string tan largo que revienta con 414 Request-URI Too Large
    // (encontrado en agosto 2026 con un cliente de 508 empleados activos).
    private const MAX_EMPLOYEE_IDS_PER_REQUEST = 80;

    /**
     * Turnos abiertos por employee_ids, resueltos por Factorial directamente
     * (a diferencia de getShifts(), no requiere filtrar clock_out === null
     * a mano — el endpoint ya solo devuelve turnos con status "opened").
     * Verificado contra la API real en agosto 2026 (funciona en 2026-04-01
     * y en 2026-07-01). Trocea employee_ids en lotes automáticamente.
     *
     * Factorial pagina a 100 filas y aquí sólo se lee la primera página (el
     * parámetro de la página 2 no se encontró, 2026-09-11). Por eso se lee
     * `meta.has_next_page`: los empleados de un lote truncado se devuelven en
     * `truncated_ids` para que quien llama no decida con datos incompletos.
     *
     * @return array{data: list<array>, truncated_ids: list<int|string>}
     */
    public function fetchOpenShifts(array $employeeIds): array
    {
        $data      = [];
        $truncated = [];

        foreach (array_chunk($employeeIds, self::MAX_EMPLOYEE_IDS_PER_REQUEST) as $chunk) {
            $response = $this->request(
                'get',
                '/api/2026-04-01/resources/attendance/open_shifts',
                ['query' => $this->buildQuery(['employee_ids' => $chunk])]
            )->json();

            $data = array_merge($data, $response['data'] ?? []);

            if ($this->pageIsTruncated($response, 'open_shifts', $chunk)) {
                $truncated = array_merge($truncated, $chunk);
            }
        }

        return ['data' => $data, 'truncated_ids' => $truncated];
    }

    /** Sólo los datos; si la respuesta vino truncada queda un warning en el log. */
    public function getOpenShifts(array $employeeIds): array
    {
        return $this->fetchOpenShifts($employeeIds)['data'];
    }

    /**
     * Horas planificadas (expected_minutes) por empleado y fecha, calculadas
     * por Factorial a partir del horario/contrato que el cliente ya configuró
     * ahí. Evita que le preguntemos al cliente algo que ya definió en Factorial.
     * Verificado contra la API real en agosto 2026. Trocea employee_ids en
     * lotes automáticamente (mismo motivo que getOpenShifts()).
     *
     * Misma paginación a 100 filas que open_shifts (riesgo R01): una fila por
     * empleado y día, así que un lote de 80 empleados × 2 días ya se trunca.
     * Pedir UNA fecha por llamada lo evita; `truncated_ids` avisa si aun así
     * pasa.
     *
     * @return array{data: list<array>, truncated_ids: list<int|string>}
     */
    public function fetchEstimatedTimes(array $employeeIds, string $startOn, string $endOn): array
    {
        $data      = [];
        $truncated = [];

        foreach (array_chunk($employeeIds, self::MAX_EMPLOYEE_IDS_PER_REQUEST) as $chunk) {
            $response = $this->request(
                'get',
                '/api/2026-04-01/resources/attendance/estimated_times',
                ['query' => $this->buildQuery([
                    'employee_ids' => $chunk,
                    'start_on'     => $startOn,
                    'end_on'       => $endOn,
                ])]
            )->json();

            $data = array_merge($data, $response['data'] ?? []);

            if ($this->pageIsTruncated($response, 'estimated_times', $chunk, ['start_on' => $startOn, 'end_on' => $endOn])) {
                $truncated = array_merge($truncated, $chunk);
            }
        }

        return ['data' => $data, 'truncated_ids' => $truncated];
    }

    /** Sólo los datos; si la respuesta vino truncada queda un warning en el log. */
    public function getEstimatedTimes(array $employeeIds, string $startOn, string $endOn): array
    {
        return $this->fetchEstimatedTimes($employeeIds, $startOn, $endOn)['data'];
    }

    private function pageIsTruncated(mixed $response, string $endpoint, array $chunk, array $context = []): bool
    {
        $meta = is_array($response) ? ($response['meta'] ?? []) : [];

        if (!is_array($meta) || !filter_var($meta['has_next_page'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        Log::warning("FactorialService: {$endpoint} devolvió has_next_page=true — sólo se leyó la primera página, lote tratado como incompleto", array_merge([
            'connection_id' => $this->connection->id,
            'empleados'     => count($chunk),
            'filas'         => is_array($response['data'] ?? null) ? count($response['data']) : null,
        ], $context));

        return true;
    }
}
