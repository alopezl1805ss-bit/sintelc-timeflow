<?php

use App\Models\BiometricSource;
use Livewire\Volt\Component;

new class extends Component {
    public function with(): array
    {
        $devices = BiometricSource::with(['client', 'location'])
            ->where('status', '!=', 'virtual')
            ->latest()
            ->limit(5)
            ->get();

        return ['devices' => $devices];
    }
}; ?>

<div class="bg-white shadow rounded-lg h-full">
    <div class="px-5 py-4 border-b border-gray-200 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-gray-900">Últimos biométricos agregados</h3>
        <a href="{{ route('devices') }}" wire:navigate class="text-xs text-indigo-600 hover:text-indigo-800">Ver todos →</a>
    </div>

    <div class="divide-y divide-gray-100">
        @forelse($devices as $device)
        <div class="px-5 py-4 space-y-0.5 font-sans">
            <p class="text-sm font-semibold text-gray-900">{{ $device->name ?? $device->serial_number }}</p>
            <p class="text-xs text-gray-500">
                {{ $device->location?->name ?? 'Sin ubicación' }} · {{ $device->client?->name ?? 'Sin empresa' }}
            </p>
            <p class="text-[10px] text-gray-400 pt-1">Agregado {{ $device->created_at->diffForHumans() }}</p>
            <p class="text-[10px] {{ $device->last_ping_at ? 'text-gray-400' : 'text-gray-300' }}">
                Última conexión: {{ $device->last_ping_at?->diffForHumans() ?? 'nunca' }}
            </p>
        </div>
        @empty
        <div class="px-5 py-10 text-center text-sm text-gray-400">Sin dispositivos registrados.</div>
        @endforelse
    </div>
</div>
