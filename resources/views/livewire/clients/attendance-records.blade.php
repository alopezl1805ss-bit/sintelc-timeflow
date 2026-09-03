<?php

use App\Exports\AttendanceReportExport;
use App\Models\AttendanceLog;
use App\Models\Client;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Client $client;

    public string $search          = '';
    public string $statusFilter    = '';
    public string $checkTypeFilter = '';
    public string $dateFrom        = '';
    public string $dateTo          = '';

    public function mount(Client $client): void
    {
        $user = auth()->user();
        if ($user->isClient()) {
            abort_if($user->client_id !== $client->id, 403);
        }

        $this->client   = $client;
        $this->dateFrom = today()->format('Y-m-d');
        $this->dateTo   = today()->format('Y-m-d');
    }

    public function updatedSearch(): void          { $this->resetPage(); }
    public function updatedStatusFilter(): void    { $this->resetPage(); }
    public function updatedCheckTypeFilter(): void { $this->resetPage(); }
    public function updatedDateFrom(): void        { $this->resetPage(); }
    public function updatedDateTo(): void          { $this->resetPage(); }

    public function exportExcel()
    {
        $filename = 'asistencia_' . str($this->client->name)->slug() . '_' . ($this->dateFrom ?? 'inicio') . '_' . ($this->dateTo ?? 'fin') . '.xlsx';

        return (new AttendanceReportExport(
            clientId:        $this->client->id,
            clientName:      $this->client->name,
            dateFrom:        $this->dateFrom ?: null,
            dateTo:          $this->dateTo   ?: null,
            search:          $this->search          ?: null,
            statusFilter:    $this->statusFilter    ?: null,
            checkTypeFilter: $this->checkTypeFilter ?: null,
        ))->download($filename);
    }

    public function clearFilters(): void
    {
        $this->search          = '';
        $this->statusFilter    = '';
        $this->checkTypeFilter = '';
        $this->dateFrom        = today()->format('Y-m-d');
        $this->dateTo          = today()->format('Y-m-d');
        $this->resetPage();
    }

    public function hasExtraFilters(): bool
    {
        return $this->search !== ''
            || $this->statusFilter !== ''
            || $this->checkTypeFilter !== '';
    }

    public function with(): array
    {
        $query = AttendanceLog::with(['biometricSource', 'factorialEmployee'])
            ->where('client_id', $this->client->id)
            ->when($this->dateFrom, fn($q) => $q->whereDate('occurred_at', '>=', $this->dateFrom))
            ->when($this->dateTo,   fn($q) => $q->whereDate('occurred_at', '<=', $this->dateTo))
            ->when($this->search, fn($q) => $q->where(function ($q2) {
                $q2->where('employee_code', 'like', "%{$this->search}%")
                   ->orWhereHas('factorialEmployee', fn($e) => $e->where('full_name', 'like', "%{$this->search}%"));
            }))
            ->when($this->statusFilter,    fn($q) => $q->where('sync_status', $this->statusFilter))
            ->when($this->checkTypeFilter, fn($q) => $q->where('check_type', $this->checkTypeFilter))
            ->orderByDesc('occurred_at');

        return [
            'logs'  => $query->paginate(25),
            'total' => AttendanceLog::where('client_id', $this->client->id)
                ->when($this->dateFrom, fn($q) => $q->whereDate('occurred_at', '>=', $this->dateFrom))
                ->when($this->dateTo,   fn($q) => $q->whereDate('occurred_at', '<=', $this->dateTo))
                ->count(),
        ];
    }
}; ?>

<div class="space-y-4">

    {{-- Filtros --}}
    <div class="bg-white shadow rounded-lg px-6 py-4">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            {{-- Rango de fechas --}}
            <div class="col-span-2 sm:col-span-2 flex items-center gap-2">
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Desde</label>
                    <input wire:model.live="dateFrom" type="date"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                </div>
                <div class="flex-1">
                    <label class="block text-xs text-gray-500 mb-1">Hasta</label>
                    <input wire:model.live="dateTo" type="date"
                        class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                </div>
            </div>

            {{-- Búsqueda --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">Buscar empleado</label>
                <input wire:model.live.debounce.300ms="search" type="text"
                    placeholder="Nombre o código..."
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
            </div>

            {{-- Tipo --}}
            <div>
                <label class="block text-xs text-gray-500 mb-1">Tipo</label>
                <select wire:model.live="checkTypeFilter"
                    class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="">Todos</option>
                    <option value="check_in">Entrada</option>
                    <option value="check_out">Salida</option>
                    <option value="break_in">Inicio descanso</option>
                    <option value="break_out">Fin descanso</option>
                </select>
            </div>
        </div>

        <div class="mt-3 flex items-center justify-between">
            {{-- Estado pills --}}
            <div class="flex items-center gap-2">
                <span class="text-xs text-gray-400 mr-1">Estado:</span>
                @foreach(['' => 'Todos', 'synced' => 'Sincronizado', 'pending' => 'Pendiente', 'resolved' => 'En proceso', 'failed' => 'Fallido', 'descartado' => 'Descartado'] as $val => $label)
                <button wire:click="$set('statusFilter', '{{ $val }}')"
                    class="px-3 py-1 rounded-full text-xs font-medium transition-colors
                        {{ $statusFilter === $val
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ $label }}
                </button>
                @endforeach
            </div>

            {{-- Total + acciones --}}
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400">{{ number_format($total) }} registros</span>
                @if($this->hasExtraFilters())
                <button wire:click="clearFilters" class="text-xs text-indigo-600 hover:text-indigo-800">
                    Limpiar filtros
                </button>
                @endif
                <button wire:click="exportExcel" wire:loading.attr="disabled"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded disabled:opacity-50 transition">
                    <svg wire:loading.remove wire:target="exportExcel" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                    </svg>
                    <svg wire:loading wire:target="exportExcel" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    Exportar Excel
                </button>
            </div>
        </div>
    </div>

    {{-- Tabla --}}
    <div class="bg-white shadow rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha / Hora</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dispositivo</th>
                        <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($logs as $log)
                    <tr class="hover:bg-gray-50">
                        <td class="px-5 py-3 whitespace-nowrap">
                            @if($log->factorialEmployee)
                                <p class="text-sm font-medium text-gray-900">{{ $log->factorialEmployee->full_name }}</p>
                                <p class="text-xs text-gray-400 font-mono">
                                    Factorial: {{ $log->factorialEmployee->factorial_id }} · Biométrico: {{ $log->employee_code }}
                                </p>
                            @else
                                <p class="text-sm font-mono text-gray-500">{{ $log->employee_code }}</p>
                                <p class="text-xs text-amber-500">Sin resolver</p>
                            @endif
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            @php
                                $typeLabels = ['check_in'=>'Entrada','check_out'=>'Salida','break_in'=>'Inicio descanso','break_out'=>'Fin descanso'];
                                $typeColors = ['check_in'=>'bg-green-100 text-green-800','check_out'=>'bg-blue-100 text-blue-800','break_in'=>'bg-yellow-100 text-yellow-800','break_out'=>'bg-purple-100 text-purple-800'];
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $typeColors[$log->check_type] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ $typeLabels[$log->check_type] ?? $log->check_type }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-sm text-gray-500 whitespace-nowrap">
                            <p>{{ $log->occurred_at->format('d/m/Y') }}</p>
                            <p class="font-mono">{{ $log->occurred_at->format('H:i:s') }}</p>
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap text-sm text-gray-500">
                            {{ $log->biometricSource?->name ?? '—' }}
                        </td>
                        <td class="px-5 py-3 whitespace-nowrap">
                            @php
                                $statusColors = ['pending'=>'bg-yellow-100 text-yellow-800','resolved'=>'bg-blue-100 text-blue-800','synced'=>'bg-green-100 text-green-800','failed'=>'bg-red-100 text-red-800','incomplete'=>'bg-gray-100 text-gray-600','descartado'=>'bg-gray-100 text-gray-400'];
                                $statusLabels = ['pending'=>'Pendiente','resolved'=>'En proceso','synced'=>'Sincronizado','failed'=>'Fallido','incomplete'=>'Incompleto','descartado'=>'Descartado'];
                                // Los cierres automáticos (attendance:close-forgotten-shifts) escriben una
                                // nota con este prefijo — se muestra visible bajo el badge, no solo al pasar
                                // el mouse, porque son casos que a veces necesitan revisión humana.
                                $isAutoClose = $log->sync_note && str_starts_with($log->sync_note, \App\Jobs\CloseForgottenShiftJob::NOTE_PREFIX);
                                $needsReview = $isAutoClose && str_contains($log->sync_note, 'revisar horas');
                            @endphp
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full cursor-default {{ $statusColors[$log->sync_status] ?? 'bg-gray-100 text-gray-800' }}"
                                @if($log->sync_error) title="{{ $log->sync_error }}"
                                @elseif($log->sync_note && !$isAutoClose) title="{{ $log->sync_note }}"
                                @endif>
                                {{ $statusLabels[$log->sync_status] ?? $log->sync_status }}
                            </span>
                            @if($isAutoClose)
                                <p class="mt-0.5 text-xs {{ $needsReview ? 'text-amber-600 font-medium' : 'text-gray-400' }}">
                                    {{ $log->sync_note }}
                                </p>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-400">
                            No hay registros para el período seleccionado.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($logs->hasPages())
        <div class="px-6 py-4 border-t border-gray-200">
            {{ $logs->links() }}
        </div>
        @endif
    </div>

</div>
