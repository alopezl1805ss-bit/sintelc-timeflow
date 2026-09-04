<?php

namespace Tests\Feature;

use App\Http\Controllers\IclockController;
use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Ventana anti-rebote del ATTLOG. Un equipo multimodal (huella + cara) reporta
 * la misma llegada varias veces con segundos de diferencia; sin este filtro cada
 * repetición chocaba con "open_shift" en Factorial y el fallback de updateShift()
 * sobrescribía el clock_in real con la lectura más tardía.
 */
class AttendanceDedupeWindowTest extends TestCase
{
    use RefreshDatabase;

    private BiometricSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        config(['attendance.dedupe_window_seconds' => 120]);
        config(['attendance.dedupe_window_overrides' => []]);

        // El repo no tiene factories para estos modelos todavía.
        $client = Client::create(['name' => 'QA Dedupe', 'slug' => 'qa-dedupe']);
        $this->source = BiometricSource::create([
            'client_id' => $client->id,
            'serial_number' => 'TEST-DEDUPE-001',
            'name' => 'TEST-DEDUPE-001',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * Este helper NO puede llamarse `run()`: PHPUnit declara `TestCase::run()`
     * como `final`, y sobreescribirlo es un error FATAL en tiempo de carga —
     * que no tumba esta prueba, tumba la SUITE ENTERA. Ni `--list-tests`
     * funciona. Comprobado con PHPUnit 11.5.56, la versión que fija composer.lock.
     */
    private function aplicar(array $records): array
    {
        $method = new ReflectionMethod(IclockController::class, 'applyDedupeWindow');
        $method->setAccessible(true);

        return $method->invoke(new IclockController(), $records, $this->source);
    }

    private function record(string $time, string $checkType = 'check_in', string $pin = 'PIN-1'): array
    {
        return [
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'factorial_employee_id' => 999,
            'employee_code' => $pin,
            'check_type' => $checkType,
            'occurred_at' => Carbon::parse($time),
            'raw_payload' => '{}',
            'sync_status' => 'resolved',
            'sync_note' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @return array<int, string> */
    private function statuses(array $out): array
    {
        return array_values(array_column($out, 'sync_status'));
    }

    public function test_gana_la_primera_lectura_de_una_rafaga(): void
    {
        $out = $this->aplicar([
            $this->record('2026-09-01 13:52:58'),
            $this->record('2026-09-01 13:53:03'),
            $this->record('2026-09-01 13:53:08'),
        ]);

        $this->assertSame(['resolved', 'descartado', 'descartado'], $this->statuses($out));
        $this->assertSame('13:52:58', $out[0]['occurred_at']->format('H:i:s'));
        $this->assertStringContainsString('13:52:58', $out[1]['sync_note']);
    }

    public function test_ordena_el_lote_antes_de_decidir(): void
    {
        // El equipo no garantiza el orden dentro del payload: la lectura más
        // temprana debe ganar aunque venga al final.
        $out = $this->aplicar([
            $this->record('2026-09-01 13:53:08'),
            $this->record('2026-09-01 13:52:58'),
            $this->record('2026-09-01 13:53:03'),
        ]);

        $this->assertSame('13:52:58', $out[0]['occurred_at']->format('H:i:s'));
        $this->assertSame(['resolved', 'descartado', 'descartado'], $this->statuses($out));
    }

    public function test_respeta_ponchadas_legitimas_separadas(): void
    {
        $out = $this->aplicar([
            $this->record('2026-09-01 13:00:00'),
            $this->record('2026-09-01 13:05:00'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_no_mezcla_check_types_distintos(): void
    {
        $out = $this->aplicar([
            $this->record('2026-09-01 13:00:00', 'check_in'),
            $this->record('2026-09-01 13:00:03', 'check_out'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_no_mezcla_empleados_distintos(): void
    {
        $out = $this->aplicar([
            $this->record('2026-09-01 13:00:00', 'check_in', 'PIN-1'),
            $this->record('2026-09-01 13:00:03', 'check_in', 'PIN-2'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_deja_intactos_los_unknown(): void
    {
        // status=255 nunca se despacha; no queremos alterar ese backlog.
        $out = $this->aplicar([
            $this->record('2026-09-01 13:00:00', 'unknown'),
            $this->record('2026-09-01 13:00:05', 'unknown'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_usa_como_ancla_una_ponchada_ya_guardada(): void
    {
        AttendanceLog::create([
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'employee_code' => 'PIN-1',
            'check_type' => 'check_in',
            'occurred_at' => Carbon::parse('2026-09-01 13:52:58'),
            'sync_status' => 'synced',
        ]);

        $out = $this->aplicar([$this->record('2026-09-01 13:53:03')]);

        $this->assertSame(['descartado'], $this->statuses($out));
    }

    public function test_una_ponchada_descartada_no_ancla_la_ventana(): void
    {
        // Si una repetición pudiera anclar, una ráfaga larga se encadenaría
        // indefinidamente y acabaría comiéndose ponchadas legítimas.
        AttendanceLog::create([
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'employee_code' => 'PIN-1',
            'check_type' => 'check_in',
            'occurred_at' => Carbon::parse('2026-09-01 13:53:03'),
            'sync_status' => 'descartado',
        ]);

        $out = $this->aplicar([$this->record('2026-09-01 13:54:00')]);

        $this->assertSame(['resolved'], $this->statuses($out));
    }

    public function test_la_ventana_se_puede_desactivar(): void
    {
        config(['attendance.dedupe_window_seconds' => 0]);

        $out = $this->aplicar([
            $this->record('2026-09-01 13:52:58'),
            $this->record('2026-09-01 13:53:03'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_override_por_cliente_tiene_prioridad(): void
    {
        config(['attendance.dedupe_window_overrides' => [$this->source->client_id => 3]]);

        $out = $this->aplicar([
            $this->record('2026-09-01 13:00:00'),
            $this->record('2026-09-01 13:00:10'),
        ]);

        // 10s > ventana de 3s del override: la segunda es legítima.
        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }
}
