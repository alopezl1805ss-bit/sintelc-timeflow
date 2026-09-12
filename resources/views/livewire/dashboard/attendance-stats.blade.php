<?php

use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Jobs\SyncAttendanceToFactorial;
use Illuminate\Support\Facades\Cache;
use Livewire\Volt\Component;

new class extends Component {
    public int $todayTotal = 0;
    public int $pendingSync = 0;
    public int $failedSync = 0;
    public int $syncedToday = 0;
    public array $byClient = [];
    public array $connections = [];
    public int $activeConnections = 0;
    public int $inactiveConnections = 0;

    // Estatus biométricos
    public int $devOnline   = 0;
    public int $devRecent   = 0;
    public int $devOffline  = 0;
    public int $devInactive = 0;

    /**
     * Cliente que se está viendo. Null = tablero global de admin.
     *
     * Incidencia H01: las acciones de este tablero (reintentar / descartar)
     * actuaban sobre los registros de TODOS los clientes a la vez. Con PIN
     * repetidos entre empresas eso era la vía de entrada a la nómina cruzada.
     */
    public ?int $clientFilterId = null;

    public function mount(?int $clientFilterId = null): void
    {
        // Un usuario de tipo cliente siempre queda acotado a su propia empresa.
        $this->clientFilterId = auth()->user()?->isClient()
            ? auth()->user()->client_id
            : $clientFilterId;

        $this->loadStats();
    }

    public function loadStats(): void
    {
        // Sync counters — cambian frecuentemente, cache corta de 30s
        $this->todayTotal  = Cache::remember('stats.today_total',  30, fn() => AttendanceLog::whereDate('occurred_at', today())->count());
        $this->pendingSync = Cache::remember('stats.pending_sync',  30, fn() => AttendanceLog::where('sync_status', 'pending')->count());
        $this->failedSync  = Cache::remember('stats.failed_sync',   30, fn() => AttendanceLog::where('sync_status', 'failed')->count());
        $this->syncedToday = Cache::remember('stats.synced_today',  30, fn() => AttendanceLog::whereDate('processed_at', today())->where('sync_status', 'synced')->count());

        // Dispositivos por empresa — cambia poco, cache de 5 min
        $this->byClient = Cache::remember('stats.by_client', 300, function () {
            $counts  = BiometricSource::selectRaw('client_id, count(*) as total')->whereNotNull('client_id')->where('status', '!=', 'virtual')->groupBy('client_id')->pluck('total', 'client_id');
            $clients = Client::whereIn('id', $counts->keys())->pluck('name', 'id');
            return $counts->map(fn($total, $clientId) => [
                'name'  => $clients[$clientId] ?? 'Sin cliente',
                'total' => $total,
            ])->values()->toArray();
        });

        // Conexiones Factorial — cambia poco, cache de 5 min
        [$this->activeConnections, $this->inactiveConnections, $this->connections] = Cache::remember('stats.connections', 300, function () {
            $all = FactorialConnection::with('client')->get();
            return [
                $all->whereNotNull('access_token')->count(),
                $all->whereNull('access_token')->count(),
                $all->map(fn($c) => ['name' => $c->client?->name ?? $c->name, 'active' => !is_null($c->access_token)])->values()->toArray(),
            ];
        });

        // Estatus biométricos — cache de 60s
        [$this->devOnline, $this->devRecent, $this->devOffline, $this->devInactive] = Cache::remember('stats.dev_status', 60, function () {
            $s = BiometricSource::selectRaw("
                SUM(CASE WHEN status='active' AND last_ping_at >= ? THEN 1 ELSE 0 END) as online,
                SUM(CASE WHEN status='active' AND last_ping_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as recent,
                SUM(CASE WHEN status='active' AND (last_ping_at IS NULL OR last_ping_at < ?) THEN 1 ELSE 0 END) as offline,
                SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) as inactive
            ", [now()->subMinutes(15), now()->subHour(), now()->subMinutes(15), now()->subHour()])->first();
            return [(int)($s->online ?? 0), (int)($s->recent ?? 0), (int)($s->offline ?? 0), (int)($s->inactive ?? 0)];
        });
    }

    /**
     * Descartar ya NO borra: marca `descartado` con nota y usuario.
     *
     * Antes hacía `delete()` físico de todos los `failed` de todos los
     * clientes: se perdía la evidencia de qué marcaje había fallado y de a
     * quién pertenecía, justo lo que hace falta para auditar H01.
     */
    public function dismissFailed(): void
    {
        $user = auth()->user();
        $sello = 'Descartado manualmente por ' . ($user?->name ?? 'sistema')
            . ' (' . ($user?->email ?? 'sin usuario') . ') el ' . now()->format('Y-m-d H:i');

        AttendanceLog::where('sync_status', 'failed')
            ->when($this->clientFilterId, fn($q) => $q->where('client_id', $this->clientFilterId))
            ->update([
                'sync_status' => 'descartado',
                'sync_error'  => null,
                'sync_note'   => $sello,
            ]);

        Cache::forget('stats.failed_sync');
        Cache::forget('stats.pending_sync');
        $this->loadStats();
        $this->dispatch('stats-refreshed');
    }

    /** El reintento global quedó desactivado por H01: sólo se reintenta un cliente concreto. */
    public function canRetryPending(): bool
    {
        return $this->clientFilterId !== null;
    }

    public function retryPending(): void
    {
        // Sin cliente en contexto no hay reintento: un reintento global es lo
        // que propagó la nómina cruzada de H01.
        if (!$this->canRetryPending()) {
            return;
        }

        $delay = 0;

        // Los que ya tienen employee mapeado → despachar directo, pero sólo si
        // ese empleado es de la misma empresa que el registro.
        AttendanceLog::where('sync_status', 'pending')
            ->where('client_id', $this->clientFilterId)
            ->whereNotNull('factorial_employee_id')
            ->orderBy('occurred_at')
            ->get(['id', 'client_id', 'factorial_employee_id'])
            ->each(function ($log) use (&$delay) {
                if (!\App\Services\AttendanceEmployeeGuard::canDispatch($log, 'dashboard:retryPending')) {
                    return;
                }

                AttendanceLog::where('id', $log->id)->update(['sync_status' => 'resolved']);
                SyncAttendanceToFactorial::dispatch($log->id)->delay(now()->addSeconds($delay));
                $delay += 2;
            });

        // Los sin mapeo + los resolved huérfanos → resolve-pending los toma,
        // acotado al mismo cliente.
        \Illuminate\Support\Facades\Artisan::call('attendance:resolve-pending', [
            '--client' => $this->clientFilterId,
        ]);

        Cache::forget('stats.pending_sync');
        $this->loadStats();
    }

    public function retryFailed(): void
    {
        $delay = 0;

        // Calcular inicio de la quincena anterior (colchón de una quincena)
        // Si estamos en la 1a quincena → guardamos desde el 16 del mes pasado
        // Si estamos en la 2a quincena → guardamos desde el 1 del mes actual
        $quincenaStart = today()->day <= 15
            ? today()->subMonthNoOverflow()->setDay(16)->startOfDay()
            : today()->startOfMonth();

        // Fuera de quincena → descartar
        AttendanceLog::where('sync_status', 'failed')
            ->where('occurred_at', '<', $quincenaStart)
            ->update([
                'sync_status' => 'descartado',
                'sync_error'  => null,
                'sync_note'   => 'Descartado automáticamente — fuera de quincena',
            ]);

        // Dentro de quincena → reintentar
        AttendanceLog::where('sync_status', 'failed')
            ->where('occurred_at', '>=', $quincenaStart)
            ->chunkById(50, function ($logs) use (&$delay) {
                // Mismo guardarraíl H01: un `failed` pudo quedar con el
                // empleado de otra empresa; reintentarlo lo mandaría a su
                // Factorial.
                $ids = $logs
                    ->filter(fn($log) => \App\Services\AttendanceEmployeeGuard::canDispatch($log, 'dashboard:retryFailed'))
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    return;
                }

                AttendanceLog::whereIn('id', $ids)->update([
                    'sync_status' => 'resolved',
                    'sync_error'  => null,
                ]);

                foreach ($ids as $id) {
                    SyncAttendanceToFactorial::dispatch($id)->delay(now()->addSeconds($delay));
                    $delay += 3;
                }
            });

        Cache::forget('stats.failed_sync');
        Cache::forget('stats.pending_sync');
        $this->loadStats();
    }
}; ?>

@php
    $clientColors = ['#6366f1','#0ea5e9','#f59e0b','#10b981','#ec4899','#8b5cf6','#14b8a6'];
    $clientTotal  = array_sum(array_column($byClient, 'total'));
    $connTotal    = $activeConnections + $inactiveConnections;
    $devTotal     = $devOnline + $devRecent + $devOffline + $devInactive;
@endphp

<div class="grid grid-cols-2 gap-5">

    {{-- Card 1: Sincronización --}}
    <div class="bg-white shadow rounded-lg p-5" wire:key="sync-card-{{ $syncedToday }}-{{ $pendingSync }}-{{ $failedSync }}">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Sincronización</p>
        <div class="flex items-center gap-8">
            @php $pct = $todayTotal > 0 ? round(($syncedToday / $todayTotal) * 100) : 0; @endphp
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: ['Sincronizados', 'Pendientes', 'Errores'],
                                datasets: [{
                                    data: [{{ $syncedToday }}, {{ $pendingSync }}, {{ $failedSync }}],
                                    backgroundColor: ['#22c55e', '#eab308', '#ef4444'],
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $todayTotal }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">registros hoy</span>
                    <span class="text-[9px] text-green-500 mt-0.5">{{ $pct }}% sync</span>
                </div>
            </div>

            <div class="flex-1 space-y-4" style="padding-left:6px;">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#22c55e;"></span>
                        <span class="text-sm text-gray-600">Sincronizados</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $syncedToday }}</span>
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#eab308;"></span>
                            <span class="text-sm text-gray-600">Pendientes</span>
                        </div>
                        <span class="text-sm font-semibold {{ $pendingSync > 0 ? 'text-amber-600' : 'text-gray-900' }}">{{ $pendingSync }}</span>
                    </div>
                    @if($pendingSync > 0)
                    <div class="flex items-center gap-3 mt-1 pl-5">
                        @if($clientFilterId)
                        <button wire:click="retryPending" wire:loading.attr="disabled"
                            class="text-xs text-amber-500 hover:text-amber-700 disabled:opacity-40 transition">
                            <span wire:loading.remove wire:target="retryPending">Reintentar</span>
                            <span wire:loading wire:target="retryPending">...</span>
                        </button>
                        @else
                        <span class="text-xs text-gray-400" title="El reintento global asignaba empleados de otra empresa a PIN repetidos (incidencia H01). Reintenta desde la ficha de cada cliente.">
                            Reintento global temporalmente desactivado — ver incidencia H01
                        </span>
                        @endif
                    </div>
                    @endif
                </div>
                <div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#ef4444;"></span>
                            <span class="text-sm text-gray-600">Errores</span>
                        </div>
                        <span class="text-sm font-semibold {{ $failedSync > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $failedSync }}</span>
                    </div>
                    @if($failedSync > 0)
                    <div class="flex items-center gap-3 mt-1 pl-5">
                        <button wire:click="retryFailed" wire:loading.attr="disabled"
                            class="text-xs text-red-500 hover:text-red-700 disabled:opacity-40 transition">
                            <span wire:loading.remove wire:target="retryFailed">Reintentar</span>
                            <span wire:loading wire:target="retryFailed">...</span>
                        </button>
                        <button wire:click="dismissFailed" wire:confirm="Se marcarán {{ $failedSync }} registros como descartados (no se borran). ¿Continuar?" wire:loading.attr="disabled"
                            class="text-xs text-gray-400 hover:text-gray-600 disabled:opacity-40 transition"
                            title="Marca los errores como «descartado» con nota y usuario. No borra nada.">
                            Descartar
                        </button>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Card 2: Conexiones Factorial --}}
    <div class="bg-white shadow rounded-lg p-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Conexiones Factorial</p>
        <div class="flex items-center gap-8">
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: ['Activas', 'Inactivas'],
                                datasets: [{
                                    data: [{{ $activeConnections }}, {{ $inactiveConnections }}],
                                    backgroundColor: ['#22c55e', '#d1d5db'],
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $activeConnections }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">activas</span>
                </div>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                @foreach($connections as $conn)
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full flex-shrink-0"
                        style="background-color:{{ $conn['active'] ? '#22c55e' : '#d1d5db' }};"></span>
                    <span class="text-sm text-gray-600">{{ mb_substr(ucwords(mb_strtolower($conn['name'])), 0, 24) }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Card 3: Dispositivos por empresa --}}
    <div class="bg-white shadow rounded-lg p-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Dispositivos por empresa</p>
        <div class="flex items-center gap-8">
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: {{ Js::from(array_column($byClient, 'name')) }},
                                datasets: [{
                                    data: {{ Js::from(array_column($byClient, 'total')) }},
                                    backgroundColor: {{ Js::from(array_slice($clientColors, 0, count($byClient))) }},
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $clientTotal }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">dispositivos</span>
                </div>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                @forelse($byClient as $i => $c)
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0"
                            style="background-color:{{ $clientColors[$i % count($clientColors)] }};"></span>
                        <span class="text-sm text-gray-600">{{ mb_substr(ucwords(mb_strtolower($c['name'])), 0, 24) }}</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $c['total'] }}</span>
                </div>
                @empty
                <p class="text-sm text-gray-400">Sin dispositivos</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Card 4: Estatus de los biométricos --}}
    <div class="bg-white shadow rounded-lg p-5">
        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Estatus biométricos</p>
        <div class="flex items-center gap-8">
            <div class="relative flex-shrink-0 w-[120px] h-[120px]">
                <canvas
                    x-data
                    x-init="
                        new Chart($el, {
                            type: 'doughnut',
                            data: {
                                labels: ['En línea', 'Reciente', 'Sin señal', 'Deshabilitado'],
                                datasets: [{
                                    data: [{{ $devOnline }}, {{ $devRecent }}, {{ $devOffline }}, {{ $devInactive }}],
                                    backgroundColor: ['#22c55e', '#eab308', '#ef4444', '#d1d5db'],
                                    borderWidth: 0,
                                    hoverOffset: 4,
                                }]
                            },
                            options: {
                                cutout: '72%',
                                plugins: { legend: { display: false }, tooltip: { enabled: true, displayColors: false, callbacks: { label: () => '' } } },
                                animation: { duration: 600 },
                            }
                        })
                    "
                ></canvas>
                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                    <span class="text-xl font-bold text-gray-900 leading-none">{{ $devTotal }}</span>
                    <span class="text-[9px] text-gray-500 mt-0.5">dispositivos</span>
                </div>
            </div>

            <div class="flex-1 space-y-3" style="padding-left:6px;">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#22c55e;"></span>
                        <span class="text-sm text-gray-600">En línea</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devOnline }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#eab308;"></span>
                        <span class="text-sm text-gray-600">Reciente</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devRecent }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#ef4444;"></span>
                        <span class="text-sm text-gray-600">Sin señal</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devOffline }}</span>
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color:#d1d5db;"></span>
                        <span class="text-sm text-gray-600">Deshabilitado</span>
                    </div>
                    <span class="text-sm font-semibold text-gray-900">{{ $devInactive }}</span>
                </div>
            </div>
        </div>
    </div>

</div>
