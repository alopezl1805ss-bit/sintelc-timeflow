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
 * Una lectura por persona, tipo y día. Complementa la ventana anti-rebote.
 *
 * La ventana cubre las repeticiones separadas por SEGUNDOS. Esta regla cubre las
 * separadas por HORAS: la persona pasa varias veces al día por el lector —mediana
 * de 4 en OUTLANDISH, hasta 5 en ACERMEX— y cada pasada viaja a Factorial como
 * una orden nueva, chocando con «Ya existe un turno» o «Turno ya editado».
 *
 * Medido sobre los datos reales del 2026-09-04: activarlo habría evitado el 75 %
 * de los fallos recientes.
 *
 * Es OPT-IN por cliente, porque en un cliente con salida a comer la segunda
 * entrada del día es legítima.
 */
class AttendanceOncePerDayTest extends TestCase
{
    use RefreshDatabase;

    private BiometricSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::create(['name' => 'QA OncePerDay', 'slug' => 'qa-once-per-day']);
        $this->source = BiometricSource::create([
            'client_id' => $client->id,
            'serial_number' => 'TEST-ONCEDAY-001',
            'name' => 'TEST-ONCEDAY-001',
            'status' => 'active',
        ]);

        // Activada para ESTE cliente. Por defecto la lista va vacía.
        config(['attendance.once_per_day_clients' => [$client->id]]);
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * `aplicar` y no `run`: PHPUnit declara `TestCase::run()` como final.
     */
    private function aplicar(array $records): array
    {
        $method = new ReflectionMethod(IclockController::class, 'applyOncePerDay');
        $method->setAccessible(true);

        return $method->invoke(new IclockController(), $records, $this->source);
    }

    private function record(string $time, string $checkType = 'check_in', string $pin = 'PIN-1', string $status = 'resolved'): array
    {
        return [
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'factorial_employee_id' => 999,
            'employee_code' => $pin,
            'check_type' => $checkType,
            'occurred_at' => Carbon::parse($time),
            'raw_payload' => '{}',
            'sync_status' => $status,
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

    // ─────────────────────────────────────────────────────────────────────────

    public function test_gana_la_primera_lectura_del_dia(): void
    {
        // El caso real de ACERMEX: cinco pasadas, cuatro horas de diferencia
        // entre algunas. La ventana anti-rebote no las alcanza.
        $out = $this->aplicar([
            $this->record('2026-09-03 05:17:00'),
            $this->record('2026-09-03 10:53:00'),
            $this->record('2026-09-03 11:29:00'),
            $this->record('2026-09-03 14:17:00'),
        ]);

        $this->assertSame(
            ['resolved', 'descartado', 'descartado', 'descartado'],
            $this->statuses($out),
        );
    }

    public function test_la_nota_dice_a_que_lectura_cede(): void
    {
        // El registro sigue visible en la pantalla del cliente. Que diga por qué
        // no se envió es la diferencia entre un descarte y una desaparición.
        $out = $this->aplicar([
            $this->record('2026-09-03 05:17:00'),
            $this->record('2026-09-03 14:17:00'),
        ]);

        $this->assertStringContainsString('05:17:00', $out[1]['sync_note']);
    }

    public function test_ordena_el_lote_antes_de_decidir(): void
    {
        // El equipo no garantiza el orden dentro del payload; si llegara al
        // revés, ganaría la lectura equivocada y el turno empezaría tarde.
        $out = $this->aplicar([
            $this->record('2026-09-03 14:17:00'),
            $this->record('2026-09-03 05:17:00'),
        ]);

        $this->assertSame('05:17:00', $out[0]['occurred_at']->format('H:i:s'));
        $this->assertSame(['resolved', 'descartado'], $this->statuses($out));
    }

    public function test_entrada_y_salida_son_independientes(): void
    {
        // Una entrada y una salida el mismo día es lo normal, no una repetición.
        $out = $this->aplicar([
            $this->record('2026-09-03 08:00:00', 'check_in'),
            $this->record('2026-09-03 17:00:00', 'check_out'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_no_mezcla_empleados_distintos(): void
    {
        $out = $this->aplicar([
            $this->record('2026-09-03 08:00:00', 'check_in', 'PIN-1'),
            $this->record('2026-09-03 08:05:00', 'check_in', 'PIN-2'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_no_mezcla_dias_distintos(): void
    {
        // La entrada de hoy no puede suprimir la de mañana.
        $out = $this->aplicar([
            $this->record('2026-09-03 08:00:00'),
            $this->record('2026-09-04 08:00:00'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_deja_intactos_los_unknown(): void
    {
        // status=255 nunca se despacha de todos modos. Se deja igual para no
        // alterar el backlog de los equipos afectados, como hace la ventana.
        $out = $this->aplicar([
            $this->record('2026-09-03 08:00:00', 'unknown'),
            $this->record('2026-09-03 09:00:00', 'unknown'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_no_reescribe_lo_que_ya_descarto_la_ventana_anti_rebote(): void
    {
        // Las dos reglas se aplican en cadena. La segunda no debe pisar la nota
        // de la primera: dicen cosas distintas y las dos son ciertas.
        $registros = [
            $this->record('2026-09-03 08:00:00'),
            $this->record('2026-09-03 08:00:03', 'check_in', 'PIN-1', 'descartado'),
        ];
        $registros[1]['sync_note'] = 'Repetición del equipo — vale la lectura de las 08:00:00';

        $out = $this->aplicar($registros);

        $this->assertSame('descartado', $out[1]['sync_status']);
        $this->assertStringContainsString('del equipo', $out[1]['sync_note']);
    }

    public function test_usa_como_ancla_una_ponchada_ya_guardada(): void
    {
        // Un lote nuevo no puede ignorar lo que llegó en el lote anterior.
        //
        // Sin `factorial_employee_id`: es una llave foránea real y el 999 del
        // helper no existe en la tabla. Mismo estilo que AttendanceDedupeWindowTest.
        AttendanceLog::create([
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'employee_code' => 'PIN-1',
            'check_type' => 'check_in',
            'occurred_at' => Carbon::parse('2026-09-03 05:17:00'),
            'sync_status' => 'synced',
        ]);

        $out = $this->aplicar([$this->record('2026-09-03 14:17:00')]);

        $this->assertSame(['descartado'], $this->statuses($out));
        $this->assertStringContainsString('05:17:00', $out[0]['sync_note']);
    }

    public function test_una_ponchada_descartada_no_sirve_de_ancla(): void
    {
        // Si lo guardado fue a su vez un descarte, no representa nada en
        // Factorial y no puede suprimir a la siguiente.
        AttendanceLog::create([
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'employee_code' => 'PIN-1',
            'check_type' => 'check_in',
            'occurred_at' => Carbon::parse('2026-09-03 05:17:00'),
            'sync_status' => 'descartado',
        ]);

        $out = $this->aplicar([$this->record('2026-09-03 14:17:00')]);

        $this->assertSame(['resolved'], $this->statuses($out));
    }

    public function test_esta_apagada_por_defecto(): void
    {
        // OPT-IN. Un cliente que no esté en la lista no cambia de comportamiento
        // en absoluto — que es lo que hace este despliegue reversible sin código.
        config(['attendance.once_per_day_clients' => []]);

        $out = $this->aplicar([
            $this->record('2026-09-03 05:17:00'),
            $this->record('2026-09-03 14:17:00'),
        ]);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    public function test_no_afecta_a_otros_clientes(): void
    {
        $otro = Client::create(['name' => 'QA Otro', 'slug' => 'qa-otro']);
        $fuente = BiometricSource::create([
            'client_id' => $otro->id,
            'serial_number' => 'TEST-OTRO-001',
            'name' => 'TEST-OTRO-001',
            'status' => 'active',
        ]);

        $method = new ReflectionMethod(IclockController::class, 'applyOncePerDay');
        $method->setAccessible(true);

        $registros = [
            ['client_id' => $otro->id, 'biometric_source_id' => $fuente->id,
                'factorial_employee_id' => 1, 'employee_code' => 'X', 'check_type' => 'check_in',
                'occurred_at' => Carbon::parse('2026-09-03 05:17:00'), 'raw_payload' => '{}',
                'sync_status' => 'resolved', 'sync_note' => null, 'created_at' => now(), 'updated_at' => now()],
            ['client_id' => $otro->id, 'biometric_source_id' => $fuente->id,
                'factorial_employee_id' => 1, 'employee_code' => 'X', 'check_type' => 'check_in',
                'occurred_at' => Carbon::parse('2026-09-03 14:17:00'), 'raw_payload' => '{}',
                'sync_status' => 'resolved', 'sync_note' => null, 'created_at' => now(), 'updated_at' => now()],
        ];

        $out = $method->invoke(new IclockController(), $registros, $fuente);

        $this->assertSame(['resolved', 'resolved'], $this->statuses($out));
    }

    // ── El guardarraíl: una salida en medio ──────────────────────────────────

    public function test_una_salida_en_medio_hace_real_la_segunda_entrada(): void
    {
        // El caso de ACERMEX. Sale a comer y vuelve: la segunda entrada es real.
        // Medido antes de activar la regla: 58 de las 131 jornadas con entrada
        // repetida de ACERMEX son esto. Sin este guardarraíl se perderían.
        $out = $this->aplicar([
            $this->record('2026-09-03 08:05:00', 'check_in'),
            $this->record('2026-09-03 14:46:00', 'check_out'),
            $this->record('2026-09-03 16:23:00', 'check_in'),
        ]);

        $this->assertSame(['resolved', 'resolved', 'resolved'], $this->statuses($out));
    }

    public function test_pero_la_pasada_siguiente_sin_salida_si_se_descarta(): void
    {
        // El mismo PIN 3423265 del 2026-09-01 tenía las DOS cosas: un regreso
        // legítimo a las 16:23 y una repetición dañina a las 17:56.
        $out = $this->aplicar([
            $this->record('2026-09-03 08:05:00', 'check_in'),
            $this->record('2026-09-03 14:46:00', 'check_out'),
            $this->record('2026-09-03 16:23:00', 'check_in'),
            $this->record('2026-09-03 17:56:00', 'check_in'),
        ]);

        $this->assertSame(['resolved', 'resolved', 'resolved', 'descartado'], $this->statuses($out));

        // Cede ante el REGRESO, no ante la llegada de la mañana: el ancla se
        // reinicia. Si dijera 08:05 significaría que la comparación se hizo
        // contra la lectura equivocada.
        $this->assertStringContainsString('16:23:00', $out[3]['sync_note']);
    }

    public function test_el_cierre_automatico_deja_empezar_un_turno_nuevo(): void
    {
        // El cierre automático escribe una salida sintética. Una entrada
        // POSTERIOR a ese cierre es un turno nuevo, no una repetición.
        AttendanceLog::create([
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'employee_code' => 'PIN-1',
            'check_type' => 'check_in',
            'occurred_at' => Carbon::parse('2026-09-03 05:00:00'),
            'sync_status' => 'synced',
        ]);
        AttendanceLog::create([
            'client_id' => $this->source->client_id,
            'biometric_source_id' => $this->source->id,
            'employee_code' => 'PIN-1',
            'check_type' => 'check_out',
            'occurred_at' => Carbon::parse('2026-09-03 13:00:00'),
            'sync_status' => 'synced',
            'sync_note' => 'Cierre automático — solo entrada (rutina)',
        ]);

        $out = $this->aplicar([$this->record('2026-09-03 14:00:00', 'check_in')]);

        $this->assertSame(['resolved'], $this->statuses($out));
    }

    public function test_la_salida_tiene_que_estar_en_MEDIO_no_despues(): void
    {
        // Una salida posterior no justifica retroactivamente la repetición.
        $out = $this->aplicar([
            $this->record('2026-09-03 08:00:00', 'check_in'),
            $this->record('2026-09-03 09:00:00', 'check_in'),
            $this->record('2026-09-03 17:00:00', 'check_out'),
        ]);

        $this->assertSame(['resolved', 'descartado', 'resolved'], $this->statuses($out));
    }

    public function test_un_unknown_en_medio_no_cuenta_como_salida(): void
    {
        // status=255 es basura del equipo, no la prueba de que la persona
        // saliera. Si contara, cualquier lectura corrupta abriría la puerta.
        $out = $this->aplicar([
            $this->record('2026-09-03 08:00:00', 'check_in'),
            $this->record('2026-09-03 12:00:00', 'unknown'),
            $this->record('2026-09-03 16:00:00', 'check_in'),
        ]);

        $this->assertSame(['resolved', 'resolved', 'descartado'], $this->statuses($out));
    }

    public function test_en_checkin_only_toda_repeticion_se_sigue_descartando(): void
    {
        // OUTLANDISH: el equipo NUNCA manda salidas, así que el guardarraíl no
        // relaja nada. Es el caso del PIN 2003002, donde la lectura de las
        // 11:20 pisó el clock_in real de las 04:26.
        $out = $this->aplicar([
            $this->record('2026-09-03 04:26:00', 'check_in'),
            $this->record('2026-09-03 11:20:00', 'check_in'),
            $this->record('2026-09-03 11:20:46', 'check_in'),
        ]);

        $this->assertSame(['resolved', 'descartado', 'descartado'], $this->statuses($out));
        $this->assertStringContainsString('04:26:00', $out[1]['sync_note']);
        $this->assertStringContainsString('04:26:00', $out[2]['sync_note']);
    }
}
