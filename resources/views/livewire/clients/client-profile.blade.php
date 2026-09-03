<?php

use App\Models\Client;
use App\Models\ClientAttendanceConfig;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Volt\Component;

new class extends Component {
    public Client $client;

    public bool $editing = false;
    public string $tab = 'info';

    // Usuarios del sistema
    public bool    $showUserModal  = false;
    public ?int    $editUserId     = null;
    public string  $userName       = '';
    public string  $userEmail      = '';
    public string  $userPassword   = '';
    public ?int    $deleteUserId   = null;

    // Campos del perfil
    public string $name                = '';
    public string $slug                = '';
    public string $status              = 'active';
    public string $oauth_client_id     = '';
    public string $oauth_client_secret = '';
    public string $hq_address          = '';
    public string $contact_email       = '';

    // Configuración de asistencia
    public string $checkin_id                    = '0';
    public string $checkout_id                   = '1';
    public bool   $has_breaks                    = false;
    public string $breakin_id                    = '';
    public string $breakout_id                   = '';
    public bool   $checkin_only                  = false;
    public bool   $auto_close_forgotten_shifts   = false;

    public function mount(): void
    {
        $this->client->load('attendanceConfig');
        $this->fillFromClient();
    }

    protected function fillFromClient(): void
    {
        $this->name                = $this->client->name;
        $this->slug                = $this->client->slug;
        $this->status              = $this->client->status;
        $this->oauth_client_id     = $this->client->oauth_client_id ?? '';
        // Nunca hidratar el secreto existente en una propiedad pública de Livewire.
        $this->oauth_client_secret = '';
        $this->hq_address          = $this->client->hq_address ?? '';
        $this->contact_email       = $this->client->contact_email ?? '';

        $config = $this->client->attendanceConfig;
        $this->checkin_id                  = $config?->checkin_id  ?? '0';
        $this->checkout_id                 = $config?->checkout_id ?? '1';
        $this->has_breaks                  = (bool) ($config?->has_breaks  ?? false);
        $this->breakin_id                  = $config?->breakin_id  ?? '';
        $this->breakout_id                 = $config?->breakout_id ?? '';
        $this->checkin_only                = (bool) ($config?->checkin_only ?? false);
        $this->auto_close_forgotten_shifts = (bool) ($config?->auto_close_forgotten_shifts ?? false);
    }

    /**
     * checkin_only implica auto_close_forgotten_shifts: si el cliente solo
     * registra entrada, el cierre automático no es opcional — sin él, el
     * siguiente check_in de cada empleado falla con "open_shift".
     */
    public function updatedCheckinOnly(bool $value): void
    {
        if ($value) {
            $this->auto_close_forgotten_shifts = true;
        }
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->fillFromClient();
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'name'                => 'required|string|max:255',
            'slug'                => 'required|string|max:255|alpha_dash',
            'status'              => 'required|in:active,inactive',
            'oauth_client_id'     => 'required|string|max:255',
            'oauth_client_secret' => 'nullable|string|max:500',
            'hq_address'          => 'nullable|string|max:500',
            'contact_email'       => 'nullable|email|max:255',
            'checkin_id'          => 'required|string|max:10',
            'checkout_id'         => 'required|string|max:10',
            'has_breaks'          => 'boolean',
            'breakin_id'          => $this->has_breaks ? 'required|string|max:10' : 'nullable',
            'breakout_id'         => $this->has_breaks ? 'required|string|max:10' : 'nullable',
            'checkin_only'                => 'boolean',
            'auto_close_forgotten_shifts' => 'boolean',
        ]);

        $clientData = [
            'name'                => $this->name,
            'slug'                => $this->slug,
            'status'              => $this->status,
            'oauth_client_id'     => $this->oauth_client_id,
            'hq_address'          => $this->hq_address ?: null,
            'contact_email'       => $this->contact_email ?: null,
        ];

        if ($this->oauth_client_secret !== '') {
            $clientData['oauth_client_secret'] = $this->oauth_client_secret;
        }

        $this->client->update($clientData);

        ClientAttendanceConfig::updateOrCreate(
            ['client_id' => $this->client->id],
            [
                'checkin_id'                  => $this->checkin_id,
                'checkout_id'                 => $this->checkout_id,
                'has_breaks'                  => $this->has_breaks,
                'breakin_id'                  => $this->has_breaks ? $this->breakin_id : null,
                'breakout_id'                 => $this->has_breaks ? $this->breakout_id : null,
                'checkin_only'                => $this->checkin_only,
                'auto_close_forgotten_shifts' => $this->checkin_only ? true : $this->auto_close_forgotten_shifts,
            ]
        );

        $this->client->refresh()->load('attendanceConfig');
        $this->editing = false;
    }

    // ── Usuarios del sistema ──────────────────────────────────────────

    public function openUserModal(?int $userId = null): void
    {
        $this->resetValidation();
        $this->editUserId   = $userId;
        $this->userPassword = '';

        if ($userId) {
            $user = User::findOrFail($userId);
            $this->userName  = $user->name;
            $this->userEmail = $user->email;
        } else {
            $this->userName  = '';
            $this->userEmail = '';
        }

        $this->showUserModal = true;
    }

    public function saveUser(): void
    {
        $rules = [
            'userName'  => 'required|string|max:255',
            'userEmail' => 'required|email|max:255|unique:users,email' . ($this->editUserId ? ",{$this->editUserId}" : ''),
        ];

        if (!$this->editUserId) {
            $rules['userPassword'] = 'required|string|min:8';
        } elseif ($this->userPassword) {
            $rules['userPassword'] = 'string|min:8';
        }

        $this->validate($rules);

        $data = [
            'name'      => $this->userName,
            'email'     => $this->userEmail,
            'role'      => 'client',
            'client_id' => $this->client->id,
        ];

        if ($this->userPassword) {
            $data['password'] = $this->userPassword;
        }

        if ($this->editUserId) {
            User::findOrFail($this->editUserId)->update($data);
        } else {
            User::create($data);
        }

        $this->showUserModal = false;
    }

    public function confirmDelete(int $userId): void
    {
        $this->deleteUserId = $userId;
    }

    public function deleteUser(): void
    {
        if ($this->deleteUserId) {
            User::findOrFail($this->deleteUserId)->delete();
            $this->deleteUserId = null;
        }
    }

    public function with(): array
    {
        return [
            'clientUsers' => User::where('client_id', $this->client->id)->orderBy('name')->get(),
        ];
    }
}; ?>

<div class="space-y-4">

{{-- ── Tab bar (card superior) ────────────────────────────────── --}}
<div class="bg-white shadow rounded-lg px-6">
    <nav class="flex gap-6 border-b border-gray-200">
        <button wire:click="$set('tab','info')"
            class="pb-3 pt-4 text-sm font-medium border-b-2 -mb-[1px] transition
                {{ $tab === 'info'
                    ? 'border-indigo-600 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
            Información de la empresa
        </button>
        <button wire:click="$set('tab','conexiones')"
            class="pb-3 pt-4 text-sm font-medium border-b-2 -mb-[1px] transition
                {{ $tab === 'conexiones'
                    ? 'border-indigo-600 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
            Conexiones
        </button>
        <button wire:click="$set('tab','usuarios')"
            class="pb-3 pt-4 text-sm font-medium border-b-2 -mb-[1px] transition
                {{ $tab === 'usuarios'
                    ? 'border-indigo-600 text-indigo-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
            Usuarios
        </button>
    </nav>
</div>

{{-- ── Contenido (card inferior) ─────────────────────────────── --}}
<div class="bg-white shadow rounded-lg overflow-hidden">

    {{-- ── Tab: Información ───────────────────────────────────────── --}}
    @if($tab === 'info')

        {{-- Header unificado: título izq + botón der --}}
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">Información de la empresa</h2>
            @if($editing)
                <div class="flex gap-2">
                    <button wire:click="cancelEdit"
                        class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 transition">
                        Cancelar
                    </button>
                    <button wire:click="save"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
                        Guardar cambios
                    </button>
                </div>
            @else
                <button wire:click="$set('editing', true)"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                    </svg>
                    Editar información
                </button>
            @endif
        </div>

            {{-- Nombre + estado --}}
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                @if($editing)
                    <input wire:model="name" type="text"
                        oninput="this.value=this.value.toUpperCase()"
                        class="block w-64 rounded-md border-gray-300 shadow-sm text-sm font-semibold focus:border-indigo-500 focus:ring-indigo-500"/>
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                @else
                    <h4 class="text-base font-semibold text-gray-900">{{ $client->name }}</h4>
                @endif
                @if($editing)
                    <select wire:model="status"
                        class="rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                @else
                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full {{ $client->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                        {{ $client->status === 'active' ? 'Activa' : 'Inactiva' }}
                    </span>
                @endif
            </div>

            {{-- Email + Slug --}}
            <div class="px-6 py-5 grid grid-cols-2 gap-6">
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Email</p>
                    @if($editing)
                        <input wire:model="contact_email" type="email" placeholder="admin@empresa.com"
                            class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('contact_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="text-sm text-gray-700">{{ $client->contact_email ?: '—' }}</p>
                    @endif
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-1">Slug</p>
                    @if($editing)
                        <input wire:model="slug" type="text"
                            class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                        @error('slug') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @else
                        <p class="text-sm font-mono text-gray-600">{{ $client->slug }}</p>
                    @endif
                </div>
            </div>

            {{-- Credenciales OAuth --}}
            <div class="border-t border-gray-100 px-6 py-5">
                <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-4">Credenciales Factorial OAuth</p>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs text-gray-400 mb-1">Client ID</p>
                        @if($editing)
                            <input wire:model="oauth_client_id" type="text" autocomplete="off"
                                placeholder="thAYmPF7qXq..."
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('oauth_client_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @else
                            <p class="text-sm font-mono text-gray-700">
                                {{ $client->oauth_client_id ? \Illuminate\Support\Str::limit($client->oauth_client_id, 36) : '—' }}
                            </p>
                        @endif
                    </div>
                    <div>
                        <p class="text-xs text-gray-400 mb-1">Client Secret</p>
                        @if($editing)
                            <input wire:model="oauth_client_secret" type="password" autocomplete="new-password"
                                placeholder="Dejar vacío para conservarlo"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('oauth_client_secret') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @else
                            <p class="text-sm font-mono text-gray-500">
                                {{ $client->oauth_client_secret ? '••••••••••••••••' : '—' }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Asistencia biométrica --}}
            <div class="border-t border-gray-100 px-6 py-5">
                <p class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-4">Asistencia biométrica</p>
                @if($editing)
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">ID Entrada</label>
                            <input wire:model="checkin_id" type="text" placeholder="0"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('checkin_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">ID Salida</label>
                            <input wire:model="checkout_id" type="text" placeholder="1"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('checkout_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        @if($has_breaks)
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">ID Inicio descanso</label>
                            <input wire:model="breakin_id" type="text" placeholder="2"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('breakin_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">ID Fin descanso</label>
                            <input wire:model="breakout_id" type="text" placeholder="3"
                                class="block w-full rounded-md border-gray-300 shadow-sm text-sm font-mono focus:border-indigo-500 focus:ring-indigo-500"/>
                            @error('breakout_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        @endif
                    </div>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input wire:model.live="has_breaks" type="checkbox"
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"/>
                        <span class="text-sm text-gray-700">¿Tiene pausas / descansos?</span>
                    </label>

                    <div class="mt-4 pt-4 border-t border-gray-100 space-y-3">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input wire:model.live="checkin_only" type="checkbox"
                                class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"/>
                            <span class="text-sm text-gray-700">
                                Solo registrar entrada (no espera salida del biométrico)
                                <span class="block text-xs text-gray-400">Cada entrada se cierra automáticamente cuando pasan las horas asignadas al turno (más un margen). Activa el cierre automático de turnos.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2 {{ $checkin_only ? 'opacity-50' : 'cursor-pointer' }}">
                            <input wire:model="auto_close_forgotten_shifts" type="checkbox" @disabled($checkin_only)
                                class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"/>
                            <span class="text-sm text-gray-700">
                                Cerrar automáticamente turnos olvidados (empleado no marcó salida)
                                <span class="block text-xs text-gray-400">Solo aplica a clientes con entrada y salida normales. Se activa solo si marcas "Solo registrar entrada".</span>
                            </span>
                        </label>
                    </div>
                @else
                    @php $config = $client->attendanceConfig; @endphp
                    <div class="flex gap-8">
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Entrada</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config?->checkin_id ?? '0' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Salida</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config?->checkout_id ?? '1' }}</p>
                        </div>
                        @if($config?->has_breaks)
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Inicio descanso</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config->breakin_id }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Fin descanso</p>
                            <p class="text-sm font-mono font-semibold text-gray-700">{{ $config->breakout_id }}</p>
                        </div>
                        @endif
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Descansos</p>
                            <p class="text-sm text-gray-700">{{ $config?->has_breaks ? 'Sí' : 'No' }}</p>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-100 flex gap-8">
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Solo entrada</p>
                            <p class="text-sm text-gray-700">{{ $config?->checkin_only ? 'Sí' : 'No' }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-400 mb-0.5">Cierre automático de turnos</p>
                            <p class="text-sm text-gray-700">{{ $config?->auto_close_forgotten_shifts ? 'Activo' : 'Inactivo' }}</p>
                        </div>
                    </div>
                @endif
            </div>
    @endif

    {{-- ── Tab: Conexiones ────────────────────────────────────────── --}}
    @if($tab === 'conexiones')
    <livewire:connections.connection-manager :client-filter-id="$client->id" />
    @endif

    {{-- ── Tab: Usuarios ──────────────────────────────────────────── --}}
    @if($tab === 'usuarios')
        {{-- Header unificado --}}
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h2 class="text-xl font-semibold text-gray-800">Usuarios del sistema</h2>
            <button wire:click="openUserModal()"
                class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 transition">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Agregar usuario
            </button>
        </div>
        @if($clientUsers->isEmpty())
            <div class="px-6 py-8 text-center text-sm text-gray-400">
                No hay usuarios registrados para esta empresa.
            </div>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($clientUsers as $u)
                <li class="px-6 py-3 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ $u->name }}</p>
                        <p class="text-xs text-gray-400">{{ $u->email }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="openUserModal({{ $u->id }})"
                            class="text-xs text-indigo-600 hover:text-indigo-800">Editar</button>
                        <button wire:click="confirmDelete({{ $u->id }})"
                            class="text-xs text-red-500 hover:text-red-700">Eliminar</button>
                    </div>
                </li>
                @endforeach
            </ul>
        @endif
    @endif

</div>{{-- /card inferior --}}

{{-- Modal crear/editar usuario --}}
@if($showUserModal)
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6 space-y-4">
        <h3 class="text-base font-semibold text-gray-900">
            {{ $editUserId ? 'Editar usuario' : 'Nuevo usuario' }}
        </h3>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Nombre</label>
            <input wire:model="userName" type="text"
                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
            @error('userName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
            <input wire:model="userEmail" type="email"
                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
            @error('userEmail') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">
                Contraseña {{ $editUserId ? '(dejar vacío para no cambiar)' : '' }}
            </label>
            <input wire:model="userPassword" type="password"
                class="block w-full rounded-md border-gray-300 shadow-sm text-sm focus:border-indigo-500 focus:ring-indigo-500"/>
            @error('userPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <button wire:click="$set('showUserModal', false)"
                class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancelar</button>
            <button wire:click="saveUser"
                class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-md transition">
                {{ $editUserId ? 'Guardar cambios' : 'Crear usuario' }}
            </button>
        </div>
    </div>
</div>
@endif

{{-- Modal confirmar eliminación --}}
@if($deleteUserId)
<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-sm mx-4 p-6 space-y-4">
        <h3 class="text-base font-semibold text-gray-900">¿Eliminar usuario?</h3>
        <p class="text-sm text-gray-500">Esta acción no se puede deshacer.</p>
        <div class="flex justify-end gap-2 pt-2">
            <button wire:click="$set('deleteUserId', null)"
                class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancelar</button>
            <button wire:click="deleteUser"
                class="px-4 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-md transition">
                Eliminar
            </button>
        </div>
    </div>
</div>
@endif

</div>{{-- /space-y-4 raíz --}}
