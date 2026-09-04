<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAttendanceToFactorial;
use App\Models\AttendanceLog;
use App\Models\BiometricSource;
use App\Models\DeviceCommand;
use App\Services\DeviceInventoryService;
use App\Services\DeviceCommandLifecycleService;
use App\Services\DeviceInfoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class IclockController extends Controller
{
    public function ping(Request $request): Response
    {
        $sn = $request->query('SN');

        Log::info('ZKTeco PING', ['query' => $request->query()]);

        if ($sn) {
            BiometricSource::updateOrCreate(
                ['serial_number' => $sn],
                ['last_ping_at' => now()]
            );
        }

        return $this->plainResponse('OK');
    }

    public function getRequest(Request $request): Response
    {
        $sn = $request->query('SN');

        Log::info('ZKTeco GETREQUEST', ['query' => $request->query()]);

        if (!$sn) {
            return $this->plainResponse('OK');
        }

        $source = BiometricSource::where('serial_number', $sn)->first();

        if (!$source) {
            return $this->plainResponse('OK');
        }

        $source->update(['last_ping_at' => now()]);

        if ($request->filled('INFO')) {
            app(DeviceInfoService::class)->capture($source->fresh(), (string) $request->query('INFO'));
            $source->refresh();
        }

        if (!$source->client_id) {
            return $this->plainResponse('OK');
        }

        $command = DeviceCommand::where('biometric_source_id', $source->id)
            ->where('status', 'pending')
            ->orderBy('command_seq')
            ->first();

        if (!$command) {
            return $this->plainResponse($this->buildGetRequestResponse());
        }

        $command->update(['status' => 'sent', 'sent_at' => now()]);
        app(DeviceCommandLifecycleService::class)->markSent($command->fresh());

        $line = $this->buildGetRequestResponse("C:{$command->command_seq}:{$command->payload}");

        return $this->plainResponse($line);
    }

    public function cdata(Request $request)
    {
        abort_if(strlen($request->getContent()) > 4_000_000, 413);

        $sn      = $request->query('SN');
        $table   = $request->query('table');
        $options = $request->query('options');

        Log::info('ZKTeco CDATA', [
            'sn'    => $sn,
            'table' => $table,
            'body'  => mb_substr($request->getContent(), 0, 500),
        ]);

        if ($options === 'all') {
            return $this->plainResponse($this->buildInitResponse($sn));
        }

        if ($table === 'options' && $sn) {
            $body = $request->getContent();
            $updates = [];
            if (preg_match('/PushVersion=([^\r\n,]+)/i', $body, $m) && !empty(trim($m[1])))
                $updates['push_version'] = trim($m[1]);
            if (preg_match('/DeviceName=([^\r\n,]+)/i', $body, $m) && !empty(trim($m[1])))
                $updates['device_name'] = trim($m[1]);
            if (!empty($updates))
                BiometricSource::where('serial_number', $sn)->update($updates);
        }

        if ($table === 'ATTLOG') {
            return $this->handleAttlog($request, $sn);
        }

        if ($table === 'rtlog') {
            return $this->handleRtlog($request, $sn);
        }

        // Los canales de inventario (usuarios, biodata) son informativos. Si uno
        // falla y devolvemos 500, el equipo se queda reintentando ese mismo
        // paquete indefinidamente y nunca llega a subir los ATTLOG. Se registra
        // el error y se responde OK para no bloquear la asistencia.
        if (in_array($table, ['USERINFO', 'user', 'OPERLOG', 'BIODATA'], true)) {
            try {
                return match ($table) {
                    'OPERLOG' => $this->handleOperlog($request, $sn),
                    'BIODATA' => $this->handleBiodata($request, $sn),
                    default   => $this->handleUserInfo($request, $sn, $table),
                };
            } catch (\Throwable $e) {
                Log::error('ZKTeco inventario: fallo al procesar tabla, se responde OK para no bloquear ATTLOG', [
                    'sn'    => $sn,
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);

                return $this->plainResponse('OK');
            }
        }

        return $this->plainResponse('OK');
    }

    public function registry(Request $request): Response
    {
        Log::info('ZKTeco REGISTRY', [
            'method'       => $request->method(),
            'query'        => $request->query(),
            'body_preview' => mb_substr($request->getContent(), 0, 1000),
        ]);

        return $this->plainResponse(
            "RegistryCode={$this->buildRegistryCode($request->query('SN'))}"
        );
    }

    public function push(Request $request): Response
    {
        Log::info('ZKTeco PUSH CONFIG REQUEST', [
            'method' => $request->method(),
            'query'  => $request->query(),
            'body'   => $request->getContent(),
        ]);

        return $this->plainResponse($this->buildPushResponse());
    }

    public function devicecmd(Request $request): Response
    {
        $sn   = $request->query('SN');
        $body = $request->getContent();

        Log::info('ZKTeco DEVICECMD RESULT', [
            'method' => $request->method(),
            'query'  => $request->query(),
            'body'   => $body,
        ]);

        // Formato de respuesta: ID=123\nReturn=0\nCMD=DATA UPDATE USERINFO...
        if ($sn) {
            $source = BiometricSource::where('serial_number', $sn)->first();

            if ($source) {
                // Extraer el ID del comando de la respuesta del equipo
                preg_match('/ID=(\d+)/i', $body, $matches);
                $commandSeq = isset($matches[1]) ? (int) $matches[1] : null;

                if ($commandSeq !== null) {
                    preg_match('/Return=(-?\d+)/i', $body, $retMatches);
                    $returnCode = isset($retMatches[1]) ? (int) $retMatches[1] : null;
                    // -1004 = PIN ya existe en el dispositivo → tratar como éxito
                    $status     = ($returnCode === 0 || $returnCode === -1004) ? 'acknowledged' : 'failed';

                    $command = DeviceCommand::where('biometric_source_id', $source->id)
                        ->where('command_seq', $commandSeq)
                        ->where('status', 'sent')
                        ->first();

                    if ($command) {
                        $command->update([
                            'status'           => $status,
                            'acknowledged_at'  => now(),
                            'device_response'  => mb_substr($body, 0, 1000),
                        ]);
                        app(DeviceCommandLifecycleService::class)->markAcknowledged(
                            $command->fresh(),
                            $status === 'acknowledged',
                            $body
                        );
                    }
                }
            }
        }

        return $this->plainResponse('OK');
    }

    // ─── Private: Handlers ───────────────────────────────────────────

    /**
     * Los equipos truncan el campo Name a un número fijo de bytes, lo que puede
     * partir por la mitad un carácter UTF-8 multibyte (p. ej. la Ñ de "MUÑOZ").
     * El byte suelto hace fallar el cast JSON de device_users. Se descartan los
     * bytes inválidos en vez de propagarlos.
     */
    private function cleanDeviceString(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $previous = mb_substitute_character();
        mb_substitute_character('none');
        $clean = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        return trim($clean);
    }

    private function handleUserInfo(Request $request, ?string $sn, string $table = 'USERINFO'): Response
    {
        $source = BiometricSource::where('serial_number', $sn)->first();

        if (!$source) {
            return $this->plainResponse('OK');
        }

        $lines = $this->splitRecords($request->getContent());
        $users = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $fields = [];
            foreach (explode("\t", $line) as $part) {
                [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
                $fields[trim($key)] = trim($val);
            }

            // Attendance PUSH Protocol: PIN=  /  Security PUSH Protocol: Pin=
            $pin = $fields['PIN'] ?? $fields['Pin'] ?? null;
            if (empty($pin)) continue;

            $users[] = [
                'pin'       => $pin,
                'name'      => $this->cleanDeviceString($fields['Name'] ?? ''),
                // Attendance PUSH: Card= / Security PUSH: CardNo=
                'card'      => $this->cleanDeviceString($fields['Card'] ?? $fields['CardNo'] ?? ''),
                // Attendance PUSH: Role= / Security PUSH: Privilege=
                'privilege' => $fields['Role'] ?? $fields['Privilege'] ?? '0',
                'protocol'  => $table === 'user' ? 'security' : 'attendance',
            ];
        }

        $source->update([
            'device_users'            => $users,
            'device_users_fetched_at' => now(),
        ]);

        app(DeviceInventoryService::class)->capture($source->fresh(), $users, 'device', [
            'table' => $table,
        ]);

        Log::info('ZKTeco USERINFO recibido', ['sn' => $sn, 'table' => $table, 'count' => count($users)]);

        return $this->plainResponse('OK');
    }

    private function handleOperlog(Request $request, ?string $sn): Response
    {
        $source = BiometricSource::where('serial_number', $sn)->first();

        if (!$source) {
            return $this->plainResponse('OK');
        }

        $body = $request->getContent();

        // Solo procesar si el body contiene registros de usuarios
        if (!str_contains($body, 'USER PIN=') && !str_contains($body, 'User PIN=')) {
            return $this->plainResponse('OK');
        }

        $users = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if (!preg_match('/^User\s+PIN=/i', $line)) continue;

            $fields = [];
            foreach (explode("\t", $line) as $part) {
                [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
                $fields[trim($key)] = trim($val);
            }

            $pin = $fields['USER PIN'] ?? $fields['PIN'] ?? null;
            if (empty($pin)) continue;

            $users[] = [
                'pin'       => $pin,
                'name'      => $this->cleanDeviceString($fields['Name'] ?? ''),
                'card'      => $this->cleanDeviceString($fields['Card'] ?? $fields['CardNo'] ?? ''),
                'privilege' => $fields['Pri'] ?? $fields['Privilege'] ?? '0',
                'protocol'  => 'operlog',
            ];
        }

        if (empty($users)) {
            return $this->plainResponse('OK');
        }

        // Merge con device_users existente para no perder usuarios de posts anteriores
        $existing = collect($source->device_users ?? [])->keyBy('pin');
        foreach ($users as $user) {
            $existing[$user['pin']] = $user;
        }

        $merged = $existing->values()->all();

        $source->update([
            'device_users'            => $merged,
            'device_users_fetched_at' => now(),
        ]);

        app(DeviceInventoryService::class)->capture($source->fresh(), $merged, 'device', [
            'table' => 'OPERLOG',
        ]);

        Log::info('ZKTeco OPERLOG usuarios recibidos', ['sn' => $sn, 'count' => count($users), 'total_merged' => count($merged)]);

        return $this->plainResponse('OK');
    }

    private function handleAttlog(Request $request, ?string $sn): Response
    {
        $source = BiometricSource::where('serial_number', $sn)
            ->where('status', 'active')
            ->first();

        if (!$source) {
            Log::warning('ZKTeco ATTLOG: dispositivo no registrado o inactivo', ['sn' => $sn]);
            return $this->plainResponse('OK: 0');
        }

        $lines = $this->splitRecords($request->getContent());
        $now   = now();

        // ── Pre-cargar todo antes del loop ───────────────────────────
        $mappings = \App\Models\BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        // PINs de empleados locales (local_name ≠ null, factorial_employee_id = null)
        $localPins = \App\Models\BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->whereNull('factorial_employee_id')
            ->whereNotNull('local_name')
            ->pluck('external_employee_code')
            ->flip();

        $factorialCompanyId = \App\Models\FactorialConnection::where('client_id', $source->client_id)
            ->whereNotNull('factorial_company_id')
            ->value('factorial_company_id');

        $attendanceConfig = \App\Models\ClientAttendanceConfig::where('client_id', $source->client_id)->first();

        // ── Pre-cargar claves únicas ya existentes (evita N+1 de exists()) ──
        $existingKeys = AttendanceLog::where('biometric_source_id', $source->id)
            ->pluck(\Illuminate\Support\Facades\DB::raw("CONCAT(employee_code, '|', occurred_at)"))
            ->flip();

        // ── Parsear líneas ───────────────────────────────────────────
        $records = [];

        foreach ($lines as $line) {
            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;

            $pin       = $parts[0] ?? null;
            $timestamp = $parts[1] ?? null;
            $status    = $parts[2] ?? null;
            $verify    = $parts[3] ?? null;
            $workcode  = $parts[4] ?? null;

            if (!$pin || !$timestamp) continue;

            try {
                $occurredAt = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $timestamp, config('app.timezone'));
            } catch (\Exception $e) {
                Log::warning('ZKTeco ATTLOG: timestamp inválido', ['raw' => $timestamp]);
                continue;
            }

            $key = $pin . '|' . $occurredAt->format('Y-m-d H:i:s');
            if (isset($existingKeys[$key])) continue;

            $employeeId = $mappings[$pin] ?? null;
            $isLocal    = !$employeeId && isset($localPins[$pin]);

            $checkType = $attendanceConfig?->resolveCheckType((string) $status)
                ?? \App\Models\ClientAttendanceConfig::defaultCheckType($status);
            $syncStatus = $checkType === 'unknown'
                ? 'pending'
                : ($employeeId ? 'resolved' : ($isLocal ? 'local' : 'pending'));

            $records[] = [
                'client_id'             => $source->client_id,
                'biometric_source_id'   => $source->id,
                'factorial_employee_id' => $employeeId,
                'employee_code'         => $pin,
                'check_type'            => $checkType,
                'occurred_at'           => $occurredAt,
                'raw_payload'           => json_encode(compact('pin', 'timestamp', 'status', 'verify', 'workcode')),
                'sync_status'           => $syncStatus,
                'sync_note'             => null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];
        }

        $records = $this->applyDedupeWindow($records, $source);
        $records = $this->applyOncePerDay($records, $source);

        if (!empty($records)) {
            AttendanceLog::insertOrIgnore($records);

            // ── Despachar jobs para records resueltos ────────────────────
            $resolved = array_filter($records, fn($r) => $r['sync_status'] === 'resolved');

            if (!empty($resolved)) {
                // Identificamos los recién insertados por su clave exacta
                // (employee_code + occurred_at) en vez de por ventana de tiempo:
                // filtrar por created_at >= now()-2min reagarraba registros de
                // lotes anteriores y volvía a despachar jobs ya despachados
                // (632 ejecuciones para 411 logs distintos el 2026-09-02).
                $insertedKeys = array_map(
                    fn($r) => $r['employee_code'] . '|' . $r['occurred_at']->format('Y-m-d H:i:s'),
                    $resolved
                );

                // Al ordenar por occurred_at ASC garantizamos que los registros
                // acumulados offline se envían a Factorial de más antiguo a más
                // reciente: check_out de ayer antes que check_in de hoy.
                $insertedIds = AttendanceLog::where('biometric_source_id', $source->id)
                    ->where('sync_status', 'resolved')
                    ->whereIn(DB::raw("CONCAT(employee_code, '|', occurred_at)"), $insertedKeys)
                    ->orderBy('occurred_at')
                    ->pluck('id');

                $delay = 0;
                foreach ($insertedIds as $logId) {
                    SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
                    $delay += 2;
                }
            }
        }

        Log::info('ZKTeco ATTLOG procesado', ['sn' => $sn, 'count' => count($records)]);

        return $this->plainResponse('OK: ' . count($records));
    }

    /**
     * Ventana anti-rebote. Los equipos multimodales (huella + cara) reportan la
     * MISMA llegada varias veces con segundos de diferencia. Sin este filtro
     * cada repetición viajaba a Factorial como un check_in nuevo, chocaba con
     * "open_shift" (409) y el fallback de updateShift() acababa sobrescribiendo
     * el clock_in real con la lectura más tardía, corriendo la hora de entrada
     * hacia adelante (OUTLANDISH, 2026-09-01: 13:52:58 pisado a 13:53:03, y el
     * cierre automático heredando el error como 21:53:03).
     *
     * Gana siempre la PRIMERA lectura. Las repeticiones se guardan con
     * sync_status 'descartado': siguen visibles en la pantalla de registros del
     * cliente — con su nota explicando a qué lectura ceden — pero nunca se
     * despachan a Factorial.
     *
     * El alcance es el CLIENTE, no el dispositivo: dos equipos del mismo
     * cliente leyendo a la misma persona con segundos de diferencia son igual
     * de duplicados que un equipo leyéndola dos veces.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function applyDedupeWindow(array $records, BiometricSource $source): array
    {
        $window = (int) (
            config("attendance.dedupe_window_overrides.{$source->client_id}")
            ?? config('attendance.dedupe_window_seconds', 120)
        );

        if ($window <= 0 || empty($records)) {
            return $records;
        }

        // Orden cronológico para que "la primera gana" aplique también dentro
        // del mismo lote — el equipo no garantiza el orden dentro del payload.
        usort($records, fn($a, $b) => $a['occurred_at'] <=> $b['occurred_at']);

        $earliest = $records[0]['occurred_at']->copy()->subSeconds($window);

        // Última ponchada NO descartada por (pin, check_type) que ya está en BD,
        // para que un lote nuevo no reviva una repetición del lote anterior.
        $previous = [];
        AttendanceLog::where('client_id', $source->client_id)
            ->whereIn('employee_code', array_unique(array_column($records, 'employee_code')))
            ->where('occurred_at', '>=', $earliest)
            ->where('sync_status', '!=', 'descartado')
            ->orderBy('occurred_at')
            ->get(['employee_code', 'check_type', 'occurred_at'])
            ->each(function ($log) use (&$previous) {
                $previous[$log->employee_code . '|' . $log->check_type] = $log->occurred_at;
            });

        $discarded = 0;

        foreach ($records as $i => $record) {
            // 'unknown' nunca se despacha de todos modos; se deja intacto para
            // no alterar el backlog de los equipos que mandan status=255.
            if ($record['check_type'] === 'unknown') {
                continue;
            }

            $key  = $record['employee_code'] . '|' . $record['check_type'];
            $last = $previous[$key] ?? null;

            if ($last && abs((int) $record['occurred_at']->diffInSeconds($last)) <= $window) {
                $records[$i]['sync_status'] = 'descartado';
                $records[$i]['sync_note']   = 'Repetición del equipo — vale la lectura de las '
                    . $last->format('H:i:s');
                $discarded++;
                continue;
            }

            // Sólo la lectura que se conserva mueve la ventana hacia adelante:
            // así una ráfaga larga no se encadena indefinidamente.
            $previous[$key] = $record['occurred_at'];
        }

        if ($discarded > 0) {
            Log::info('ZKTeco ATTLOG: repeticiones descartadas por ventana anti-rebote', [
                'sn'        => $source->serial_number,
                'client_id' => $source->client_id,
                'window_s'  => $window,
                'discarded' => $discarded,
            ]);
        }

        return $records;
    }

    /**
     * Una lectura por persona, tipo y DÍA. Complementa la ventana anti-rebote.
     *
     * ── El hueco que cubre ──────────────────────────────────────────────────
     *
     * `applyDedupeWindow` resuelve las repeticiones separadas por SEGUNDOS: el
     * equipo multimodal leyendo la misma llegada dos veces. Funciona, y desde
     * que está activa OUTLANDISH pasó de 212 fallos en un día a 2.
     *
     * Pero queda otra forma del mismo problema, separada por HORAS. La gente
     * pasa por el lector varias veces al día —mediana de 4 en OUTLANDISH, hasta
     * 5 en ACERMEX— y el equipo etiqueta entrada/salida de forma inconsistente.
     * Cada repetición viaja a Factorial como una orden nueva y choca:
     *
     *     05:17  check_out  → «No existe ningún turno abierto»
     *     05:17  check_in   → aceptado
     *     10:53  check_in   → aceptado
     *     11:29  check_in   → «Turno ya editado por biométrico SFT»
     *     14:17  check_in   → «Turno ya editado por biométrico SFT»
     *
     * Medido sobre los datos reales: suprimir esas repeticiones habría evitado
     * el 75 % de los fallos de los últimos días y el 71 % desde agosto.
     *
     * ── El guardarraíl: una salida en medio hace que la entrada sea real ───
     *
     * La primera versión suprimía TODA entrada repetida del día. Medido contra
     * los datos reales ANTES de activarla, eso habría sido correcto en
     * OUTLANDISH y destructivo en ACERMEX (14 días, 2026-09-04):
     *
     *     OUTLANDISH   271 jornadas con entrada repetida ·  0 con salida en medio
     *     ACERMEX      131 jornadas con entrada repetida · 58 con salida en medio
     *
     * Esas 58 son gente que salió a comer y volvió: la segunda entrada es REAL.
     * Caso textual, PIN 3437436, 2026-08-28:
     *
     *     14:07  check_in    ← llegada
     *     15:51  check_out   ← sale
     *     18:05  check_in    ← vuelve. Real. Descartarla sería perder horas.
     *
     * Así que la regla NO es «una entrada por día». Es: se descarta una lectura
     * repetida solo si no hay una lectura del tipo OPUESTO entre ella y la
     * anterior del mismo tipo. Una salida en medio reinicia el ancla.
     *
     * En OUTLANDISH esto no relaja nada —es `checkin_only`, el equipo nunca
     * manda salidas— y de paso añade un acierto: el cierre automático escribe
     * una salida sintética, así que una entrada POSTERIOR a ese cierre se
     * reconoce como turno nuevo en vez de perderse.
     *
     * ── Lo que sigue costando ──────────────────────────────────────────────
     *
     * Si alguien sale y vuelve pero el equipo no registró la salida, su regreso
     * se descarta. Es el caso que queda, y es pequeño frente a lo que evita.
     *
     * Caso documentado, OUTLANDISH, PIN 2003002, 2026-08-27:
     *
     *     04:26  check_in   synced  «directo»          ← llegada real
     *     11:20  check_in   synced  «overwrite (api)»  ← pisa el clock_in
     *     22:20  check_out  synced  cierre automático  ← hereda la hora mala
     *
     * Su turno quedó como 11:20→22:20 en vez de arrancar a las 04:26. En 14
     * días eso le pasó a 294 lecturas que figuran como «synced»: no fallan,
     * corrompen en silencio. Suprimir la de las 11:20 no pierde un dato bueno
     * — impide que uno roto siga estropeando el que sí funcionaba.
     *
     * ── Y es una mitigación, no la solución ─────────────────────────────────
     *
     * La solución de fondo es derivar la jornada completa de la persona a
     * partir de todos sus marcajes del día —alternancia incluida— y hacer
     * converger Factorial hacia ella, en vez de emitir una orden por marcaje.
     * Eso es un cambio de modelo, no un parche. Esto compra tiempo.
     *
     * El día laboral es el día natural en la zona del servidor
     * (America/Mexico_City). No se introduce aquí una hora de corte
     * configurable: sería un concepto nuevo, y este cambio quiere ser pequeño.
     * Los turnos nocturnos de un cliente que cruce medianoche NO deben activar
     * esta regla.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function applyOncePerDay(array $records, BiometricSource $source): array
    {
        $clientes = (array) config('attendance.once_per_day_clients', []);

        if (empty($records) || ! in_array($source->client_id, $clientes, true)) {
            return $records;
        }

        // Cronológico: «la primera gana» tiene que aplicar también dentro del
        // lote, y el equipo no garantiza el orden dentro del payload.
        usort($records, fn ($a, $b) => $a['occurred_at'] <=> $b['occurred_at']);

        // Días naturales tocados por este lote, para acotar la consulta.
        $dias = array_unique(array_map(
            fn ($r) => $r['occurred_at']->copy()->startOfDay()->toDateTimeString(),
            $records,
        ));

        // La línea de tiempo completa de cada persona y día: lo ya guardado más
        // lo que se vaya aceptando de este lote.
        //
        // Hace falta ENTERA, no solo la primera lectura de cada tipo, porque la
        // decisión depende de si hubo una lectura opuesta en medio. Se excluye
        // lo descartado para que un lote nuevo no reviva una repetición vieja.
        $linea = [];

        AttendanceLog::where('client_id', $source->client_id)
            ->whereIn('employee_code', array_unique(array_column($records, 'employee_code')))
            ->where('occurred_at', '>=', min($dias))
            ->where('occurred_at', '<', date('Y-m-d H:i:s', strtotime(max($dias) . ' +1 day')))
            ->where('sync_status', '!=', 'descartado')
            ->orderBy('occurred_at')
            ->get(['employee_code', 'check_type', 'occurred_at'])
            ->each(function ($log) use (&$linea) {
                $dia = $log->employee_code . '|' . $log->occurred_at->toDateString();
                $linea[$dia][] = ['t' => $log->occurred_at, 'tipo' => $log->check_type];
            });

        $descartados = 0;

        foreach ($records as $i => $record) {
            // 'unknown' nunca se despacha; se deja intacto para no alterar el
            // backlog de los equipos que mandan status=255. Igual que en la
            // ventana anti-rebote.
            if ($record['check_type'] === 'unknown') {
                continue;
            }

            // Y lo que la ventana anti-rebote ya descartó no se vuelve a tocar:
            // las dos notas dicen cosas distintas y las dos son ciertas.
            if (($record['sync_status'] ?? null) === 'descartado') {
                continue;
            }

            $dia = $record['employee_code'] . '|' . $record['occurred_at']->toDateString();
            $ancla = $this->lastOfType($linea[$dia] ?? [], $record['check_type']);

            $esRepeticion = $ancla !== null && ! $this->hasOppositeBetween(
                $linea[$dia] ?? [],
                $record['check_type'],
                $ancla,
                $record['occurred_at'],
            );

            if ($esRepeticion) {
                $records[$i]['sync_status'] = 'descartado';
                $records[$i]['sync_note'] = 'Repetición del día — vale la lectura de las '
                    . $ancla->format('H:i:s');
                $descartados++;

                continue;
            }

            $linea[$dia][] = ['t' => $record['occurred_at'], 'tipo' => $record['check_type']];
        }

        if ($descartados > 0) {
            Log::info('ZKTeco ATTLOG: repeticiones descartadas por regla de una por día', [
                'sn'          => $source->serial_number,
                'client_id'   => $source->client_id,
                'descartados' => $descartados,
            ]);
        }

        return $records;
    }

    /**
     * El tipo opuesto — el que convierte una repetición en un regreso real.
     *
     * Solo el opuesto EXACTO cuenta. Un 'unknown' en medio no reinicia nada:
     * es basura de un equipo con status=255, no la prueba de que la persona
     * saliera.
     */
    private const OPPOSITE_CHECK_TYPE = [
        'check_in'   => 'check_out',
        'check_out'  => 'check_in',
        'break_in'   => 'break_out',
        'break_out'  => 'break_in',
    ];

    /**
     * La última lectura de ese tipo en el día, o null si no hay ninguna.
     *
     * La ÚLTIMA y no la primera: tras un regreso legítimo, el ancla pasa a ser
     * la entrada del regreso. Si no, una tercera pasada se compararía con la
     * llegada de la mañana y sobreviviría por tener una salida en medio.
     *
     * @param  array<int, array{t: \Carbon\Carbon, tipo: string}>  $linea
     */
    private function lastOfType(array $linea, string $tipo): ?\Carbon\Carbon
    {
        $ultima = null;

        foreach ($linea as $x) {
            if ($x['tipo'] === $tipo && ($ultima === null || $x['t'] > $ultima)) {
                $ultima = $x['t'];
            }
        }

        return $ultima;
    }

    /**
     * ¿Hay una lectura del tipo opuesto estrictamente entre las dos horas?
     *
     * Un tipo sin opuesto conocido devuelve `true` —o sea, no se descarta—.
     * Ante la duda, el registro sobrevive: perder una ponchada real es peor que
     * dejar pasar una repetición, que a lo sumo vuelve a fallar como hoy.
     *
     * @param  array<int, array{t: \Carbon\Carbon, tipo: string}>  $linea
     */
    private function hasOppositeBetween(array $linea, string $tipo, \Carbon\Carbon $desde, \Carbon\Carbon $hasta): bool
    {
        $opuesto = self::OPPOSITE_CHECK_TYPE[$tipo] ?? null;

        if ($opuesto === null) {
            return true;
        }

        foreach ($linea as $x) {
            if ($x['tipo'] === $opuesto && $x['t'] > $desde && $x['t'] < $hasta) {
                return true;
            }
        }

        return false;
    }

    private function handleBiodata(Request $request, ?string $sn): Response
    {
        if (!$sn) return $this->plainResponse('OK');

        $source = BiometricSource::where('serial_number', $sn)->first();
        if (!$source) return $this->plainResponse('OK');

        $body = $request->getContent();
        if (empty(trim($body))) return $this->plainResponse('OK');

        // Parsear líneas: cada línea es un registro biométrico de un usuario
        $records = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $records[] = $line;
        }

        if (empty($records)) return $this->plainResponse('OK');

        $source->update([
            'biodata_cache'     => $records,
            'biodata_cached_at' => now(),
        ]);

        Log::info('ZKTeco BIODATA capturado', ['sn' => $sn, 'records' => count($records)]);

        // Si hay un clon pendiente, encolar UPDATE BIODATA al target
        if ($source->clone_target_id) {
            $target = BiometricSource::find($source->clone_target_id);
            if ($target) {
                $seq = DeviceCommand::where('biometric_source_id', $target->id)->max('command_seq') + 1;
                foreach ($records as $i => $record) {
                    DeviceCommand::create([
                        'biometric_source_id' => $target->id,
                        'command_seq'         => $seq + $i,
                        'command_type'        => 'push_biodata',
                        'payload'             => 'DATA UPDATE BIODATA ' . $record,
                        'status'              => 'pending',
                    ]);
                }
                Log::info('ZKTeco BIODATA clone encolado', [
                    'source' => $sn,
                    'target' => $target->serial_number,
                    'count'  => count($records),
                ]);
            }
            $source->update(['clone_target_id' => null]);
        }

        return $this->plainResponse('OK');
    }

    private function handleRtlog(Request $request, ?string $sn): Response
    {
        $source = BiometricSource::where('serial_number', $sn)
            ->where('status', 'active')
            ->first();

        if (!$source) {
            return $this->plainResponse('OK');
        }

        // rtlog format: time=Y-m-d H:i:s\tpin=X\tinoutstatus=X\t...
        $fields = [];
        foreach (explode("\t", trim($request->getContent())) as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
            $fields[trim($key)] = trim($val);
        }

        $pin    = $fields['pin'] ?? null;
        $time   = $fields['time'] ?? null;
        $status = $fields['inoutstatus'] ?? null;

        // pin=0 son eventos de sistema (puerta, alarma), no marcajes de empleados
        if (!$pin || $pin === '0' || !$time) {
            return $this->plainResponse('OK');
        }

        try {
            $occurredAt = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $time, config('app.timezone'));
        } catch (\Exception $e) {
            return $this->plainResponse('OK');
        }

        $attendanceConfig = \App\Models\ClientAttendanceConfig::where('client_id', $source->client_id)->first();

        $employeeId = \App\Models\BiometricUserSync::where('biometric_provider_id', $source->biometric_provider_id)
            ->where('external_employee_code', $pin)
            ->value('factorial_employee_id');

        $checkType = $attendanceConfig?->resolveCheckType((string) $status)
            ?? \App\Models\ClientAttendanceConfig::defaultCheckType($status);

        try {
            $log = AttendanceLog::create([
                'client_id'             => $source->client_id,
                'biometric_source_id'   => $source->id,
                'factorial_employee_id' => $employeeId,
                'employee_code'         => $pin,
                'check_type'            => $checkType,
                'occurred_at'           => $occurredAt,
                'raw_payload'           => json_encode($fields),
                'sync_status'           => $employeeId && $checkType !== 'unknown' ? 'resolved' : 'pending',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                return $this->plainResponse('OK');
            }
            throw $e;
        }

        if ($employeeId && $checkType !== 'unknown') {
            SyncAttendanceToFactorial::dispatch($log->id);
        }

        Log::info('ZKTeco rtlog procesado', ['sn' => $sn, 'pin' => $pin, 'time' => $time]);

        return $this->plainResponse('OK');
    }

    // ─── Private: Builders ───────────────────────────────────────────

    private function buildGetRequestResponse(string $command = 'OK'): string
    {
        return $command;
    }

    private function buildInitResponse(?string $sn): string
    {
        return "GET OPTION FROM: {$sn}\n"
            . "Stamp=0\n"
            . "TimeZone=-6\n"
            . "TransFlag=111111111111\n"
            . "PushProtVer=2.4.1\n";
    }

    private function buildPushResponse(): string
    {
        return implode("\n", [
            'ServerVersion=1.0.0',
            'ServerName=Sintelc ADMS',
            'PushVersion=2.4.2',
            'ErrorDelay=60',
            'RequestDelay=2',
            'TransTimes=00:00;14:00',
            'TransInterval=1',
            'TransTables=Transaction',  // solo asistencia; usuarios solo bajo petición explícita (DATA QUERY USERINFO)
            'Realtime=1',
            'SessionID=demo-session-id',
            'TimeoutSec=10',
        ]) . "\n";
    }

    private function buildRegistryCode(?string $sn): string
    {
        return substr(md5(($sn ?? 'unknown') . '|sintelc'), 0, 16);
    }

    // ─── Private: Utilities ──────────────────────────────────────────

    private function splitRecords(string $body): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $body))
        ));
    }

    private function plainResponse(string $body, int $status = 200): Response
    {
        return response($body, $status)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Length', strlen($body))
            ->header('Connection', 'close');
    }
}
