<?php

namespace Tests\Feature;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\Client;
use App\Models\FactorialConnection;
use App\Models\FactorialEmployee;
use App\Services\AttendanceEmployeeGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Incidencia H01 — nómina cruzada entre empresas.
 *
 * `attendance:resolve-pending` construía el mapa PIN → empleado con un `pluck`
 * global sobre todos los `biometric_user_syncs` y lo aplicaba a los logs
 * `pending` de cualquier cliente. Los PIN cortos se repiten entre empresas
 * (1, 2, 3, 5, 9, 45…), así que un marcaje de la empresa B con PIN 5 tomaba al
 * empleado con PIN 5 de la empresa A y le creaba turnos en SU Factorial.
 * Daño medido el 2026-09-12: 80 marcajes cruzados, 54 ya sincronizados.
 *
 * Estas pruebas fijan el contorno: el mapa es por cliente, un PIN sin mapeo en
 * su propia empresa se queda `pending`, y nada se despacha si el empleado
 * elegido no pertenece al cliente del registro.
 */
class ResolvePendingAttendanceLogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El guardarraíl memoiza dueños por proceso y los ids se reciclan
        // entre pruebas con RefreshDatabase.
        AttendanceEmployeeGuard::flush();
    }

    /** @return array{client: Client, conn: FactorialConnection, provider: BiometricProvider, source: BiometricSource} */
    private function empresa(string $slug): array
    {
        $client = Client::create(['name' => strtoupper($slug), 'slug' => $slug]);

        $conn = FactorialConnection::create([
            'client_id'           => $client->id,
            'name'                => "conn-{$slug}",
            'resource_owner_type' => 'company',
            'access_token'        => 'token-' . $slug,
        ]);

        $provider = BiometricProvider::create([
            'client_id'               => $client->id,
            'factorial_connection_id' => $conn->id,
            'vendor'                  => 'zkteco',
            'status'                  => 'active',
        ]);

        $source = BiometricSource::create([
            'client_id'             => $client->id,
            'biometric_provider_id' => $provider->id,
            'name'                  => "dev-{$slug}",
            'serial_number'         => 'SN-' . strtoupper($slug),
            'status'                => 'active',
        ]);

        return ['client' => $client, 'conn' => $conn, 'provider' => $provider, 'source' => $source];
    }

    private function empleado(array $empresa, int $factorialId, string $nombre): FactorialEmployee
    {
        return FactorialEmployee::create([
            'client_id'               => $empresa['client']->id,
            'factorial_connection_id' => $empresa['conn']->id,
            'factorial_id'            => $factorialId,
            'full_name'               => $nombre,
            'company_id'              => $factorialId,
            'active'                  => true,
            'attendable'              => true,
        ]);
    }

    private function mapeo(array $empresa, string $pin, ?FactorialEmployee $empleado, ?string $localName = null): BiometricUserSync
    {
        return BiometricUserSync::create([
            'client_id'              => $empresa['client']->id,
            'biometric_provider_id'  => $empresa['provider']->id,
            'factorial_employee_id'  => $empleado?->id,
            'external_employee_code' => $pin,
            'local_name'             => $localName,
            'sync_status'            => 'synced',
        ]);
    }

    private function marcaje(array $empresa, string $pin, string $status = 'pending', ?int $employeeId = null): AttendanceLog
    {
        return AttendanceLog::create([
            'client_id'             => $empresa['client']->id,
            'biometric_source_id'   => $empresa['source']->id,
            'factorial_employee_id' => $employeeId,
            'employee_code'         => $pin,
            'check_type'            => 'check_in',
            'occurred_at'           => now()->subHours(2),
            'sync_status'           => $status,
        ]);
    }

    public function test_no_cruza_clientes(): void
    {
        Queue::fake();

        $a = $this->empresa('empresa-a');
        $b = $this->empresa('empresa-b');

        $empleadoA = $this->empleado($a, 30, 'Empleado A');
        $this->mapeo($a, '5', $empleadoA);

        // La empresa B usa el MISMO PIN y no tiene mapeo para él.
        $logA = $this->marcaje($a, '5');
        $logB = $this->marcaje($b, '5');

        Artisan::call('attendance:resolve-pending');

        $logA->refresh();
        $this->assertSame($empleadoA->id, $logA->factorial_employee_id);
        $this->assertSame('resolved', $logA->sync_status);

        $logB->refresh();
        $this->assertNull($logB->factorial_employee_id, 'El log de B no debe recibir el empleado de A');
        $this->assertSame('pending', $logB->sync_status);

        Queue::assertPushed(SyncAttendanceToFactorial::class, 1);
        Queue::assertPushed(
            SyncAttendanceToFactorial::class,
            fn ($job) => $job->attendanceLogId === $logA->id
        );
    }

    public function test_resuelve_dentro_del_mismo_cliente(): void
    {
        Queue::fake();

        $a = $this->empresa('empresa-feliz');
        $empleado = $this->empleado($a, 77, 'Empleada Feliz');
        $this->mapeo($a, '45', $empleado);

        $log = $this->marcaje($a, '45');

        Artisan::call('attendance:resolve-pending');

        $log->refresh();
        $this->assertSame($empleado->id, $log->factorial_employee_id);
        $this->assertSame('resolved', $log->sync_status);

        Queue::assertPushed(
            SyncAttendanceToFactorial::class,
            fn ($job) => $job->attendanceLogId === $log->id
        );
    }

    public function test_pin_local_no_cruza_clientes(): void
    {
        Queue::fake();

        $a = $this->empresa('local-a');
        $b = $this->empresa('local-b');

        // Empleado sólo local (sin Factorial) en la empresa A, PIN 9.
        $this->mapeo($a, '9', null, 'Velador Local');

        $logA = $this->marcaje($a, '9');
        $logB = $this->marcaje($b, '9');

        Artisan::call('attendance:resolve-pending');

        $this->assertSame('local', $logA->refresh()->sync_status);
        $this->assertSame('pending', $logB->refresh()->sync_status, 'El PIN local de A no debe marcar los logs de B');

        Queue::assertNothingPushed();
    }

    public function test_no_despacha_si_el_empleado_no_pertenece_al_cliente(): void
    {
        Queue::fake();
        Log::spy();

        $a = $this->empresa('guard-a');
        $b = $this->empresa('guard-b');

        $empleadoA = $this->empleado($a, 2725, 'Empleado Ajeno');

        // Cruce ya grabado en la tabla (así quedaron los 54 registros
        // sincronizados): un log de B con el empleado de A, en `resolved` y
        // huérfano, que el comando re-despacharía.
        $cruzado = $this->marcaje($b, '2', 'resolved', $empleadoA->id);
        AttendanceLog::where('id', $cruzado->id)->update(['updated_at' => now()->subMinutes(30)]);

        // Y un mapeo corrupto: fila de B apuntando al empleado de A.
        $this->mapeo($b, '3', $empleadoA);
        $pendiente = $this->marcaje($b, '3');

        Artisan::call('attendance:resolve-pending');

        Queue::assertNothingPushed();

        $pendiente->refresh();
        $this->assertNull($pendiente->factorial_employee_id);
        $this->assertSame('pending', $pendiente->sync_status);

        Log::shouldHaveReceived('critical')
            ->twice()
            ->withArgs(fn ($mensaje) => str_contains($mensaje, 'H01'));
    }

    public function test_dry_run_no_escribe_ni_despacha(): void
    {
        Queue::fake();

        $a = $this->empresa('dry-run');
        $empleado = $this->empleado($a, 12, 'Empleado Dry');
        $this->mapeo($a, '1', $empleado);
        $log = $this->marcaje($a, '1');

        Artisan::call('attendance:resolve-pending', ['--dry-run' => true]);

        $log->refresh();
        $this->assertNull($log->factorial_employee_id);
        $this->assertSame('pending', $log->sync_status);
        Queue::assertNothingPushed();
    }

    public function test_client_no_numerico_aborta_sin_tocar_nada(): void
    {
        Queue::fake();
        $this->assertSame(\Illuminate\Console\Command::INVALID, Artisan::call('attendance:resolve-pending', ['--client' => 'abc']));
        $this->assertSame(\Illuminate\Console\Command::INVALID, Artisan::call('attendance:resolve-pending', ['--client' => '0']));
        Queue::assertNothingPushed();
    }

    public function test_client_inexistente_aborta_sin_tocar_nada(): void
    {
        Queue::fake();
        $this->assertSame(\Illuminate\Console\Command::INVALID, Artisan::call('attendance:resolve-pending', ['--client' => '999999']));
        Queue::assertNothingPushed();
    }
}
