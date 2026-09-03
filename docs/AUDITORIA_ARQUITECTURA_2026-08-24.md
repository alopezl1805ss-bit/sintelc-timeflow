# Auditoría de arquitectura — Sintelc FlowTime

**Fecha:** 2026-08-24
**Alcance:** todo el repositorio (`/var/www/sintelcft`), código + migraciones + tests + documentación interna, contrastado contra el estado real de producción.
**Estado en producción al momento de la auditoría:** 11 clientes, 28 dispositivos biométricos activos, 12 conexiones Factorial con token activo.

> Este documento es una foto puntual. El código evoluciona rápido (ver el historial de commits reciente: reversión de `toggle_clock`, nuevo mecanismo de cierre de turnos olvidados el 2026-08-17). Cualquier hallazgo aquí debe reverificarse contra el código antes de actuar si pasa mucho tiempo.

---

## 1. Resumen ejecutivo

**Qué es:** Sintelc FlowTime es el middleware que conecta relojes biométricos ZKTeco de las empresas cliente con la cuenta de Factorial HR de cada una. Recibe marcajes (entrada/salida) del dispositivo físico, decide qué empleado y qué tipo de marcaje es, y lo empuja a Factorial como turno de asistencia (`shift`). También administra remotamente los propios dispositivos (alta de usuarios, comandos, inventario) y sincroniza catálogos (empleados, ubicaciones) desde Factorial hacia su propia base.

**Lo que funciona bien:**
- El flujo feliz (dispositivo → Factorial) es sólido: idempotencia por clave única en BD, verificación de idempotencia contra la propia API de Factorial antes de fallar, reintentos con backoff donde importa (cierre de turnos), *no* reintentos donde reintentar sería peligroso (sync de asistencia, para no duplicar marcajes).
- Multi-tenencia limpia: todo cuelga de `client_id`, tokens OAuth cifrados en BD, un `FactorialService` por conexión.
- Documentación interna (`CLAUDE.md`, `docs/biometric-protocol-routing.md`, comentarios en código) es inusualmente honesta: registra explícitamente huecos conocidos en vez de esconderlos.

**Lo más importante que arreglar:**
1. **`employee-sync-manager.blade.php` no excluye dispositivos `status='virtual'`** (confirmado en esta auditoría, 13 queries a `BiometricSource` sin el filtro) — para cualquier cliente con cierre automático activo, el dispositivo sintético `AUTOCLOSE-{client_id}` aparece como un dispositivo real seleccionable. Gap ya anotado en `CLAUDE.md` como pendiente; esta auditoría lo confirma con ubicación exacta.
2. **Sin `edit_timesheet_requests` en Factorial** para turnos olvidados reales (`checkin_only=false`) — la única señal de "esto necesita revisión humana" es un log y una nota de color en una pantalla interna. Si nadie mira esa pantalla, el turno queda mal marcado sin que Factorial ni el cliente se enteren por su flujo nativo.
3. **`app/Jobs_old/` y `app/Services_old/`** son copias completas de clases reales con el **mismo namespace y mismo nombre de clase** que las vigentes (`App\Jobs\SyncAttendanceToFactorial`, `App\Services\FactorialService`, etc.), sin trackear en git. No rompen el autoload porque Composer las descarta por no cumplir PSR-4, pero es basura de alto riesgo: cualquier `require` manual, `git add -A`, o reorganización futura puede resucitar código viejo con bugs ya corregidos (falta el fallback de "turno cerrado antes de tiempo", por ejemplo).
4. **`expected_minutes == 0` se trata igual que "sin horario"** en el cierre automático de turnos — un empleado con día de descanso real configurado en Factorial recibe 8h de turno "fantasma" en vez de 0. Ya documentado como hueco conocido, sigue sin resolver.
5. **El revert de `toggle_clock`** (commit `3f6e47e`) dejó vigente otra vez el riesgo de `open_shift` en días de 0 horas — aceptado conscientemente, pero sin mitigación activa más allá del comando de diagnóstico manual `factorial:list-open-shifts`.

El resto del documento desarrolla cada pieza con detalle: qué entra, qué sale, por qué se diseñó así, y qué se rompe cuando algo no encaja.

---

## 2. Contexto de negocio y stack

- **Producto:** `app.sintelcft.dev`, Laravel 12 + Livewire 3 / Volt (componentes de archivo único, sin controladores separados para la UI).
- **Modelo:** multi-tenant — un solo servidor Laravel atiende a N empresas cliente (`Client`), cada una con su propia conexión OAuth a Factorial (`FactorialConnection`) y sus propios dispositivos (`BiometricSource`).
- **Infraestructura:** AWS EC2 t3.micro (única instancia, sin balanceo ni HA), MySQL, cola y caché sobre el driver `database` (no Redis), un solo worker de cola vía Supervisor.
- **Dependencias clave:** `laravel/framework ^12`, `livewire/livewire ^3.6`, `livewire/volt ^1.7`, `vinkla/hashids` (para no exponer IDs de conexión en URLs de OAuth).
- **Sin frontend SPA:** todo servidor-renderizado con Blade + Livewire; sin API pública para terceros salvo el propio protocolo ZKTeco y el callback OAuth de Factorial.

### 2.1 Diagrama de flujo general

```
┌──────────────┐   ATTLOG/USERINFO/OPERLOG/BIODATA (HTTP plano, sin auth)
│ Reloj ZKTeco │ ──────────────────────────────────────────────────┐
└──────────────┘                                                   ▼
       ▲                                            ┌───────────────────────────┐
       │ getrequest (poll, cada pocos seg)           │  IclockController          │
       │ devicecmd (ACK de comandos)                 │  (/iclock/*, sin sesión)   │
       └───────────────────────────◄─────────────────┴──────────────┬────────────┘
                                                                     │ inserta
                                                                     ▼
                                                          ┌─────────────────────┐
                                                          │   AttendanceLog     │
                                                          └──────────┬──────────┘
                                                                     │ dispatch (delay escalonado)
                                                                     ▼
                                                     ┌───────────────────────────────┐
                                                     │ SyncAttendanceToFactorial job  │
                                                     │ (tries=1 — nunca reintenta)    │
                                                     └───────────────┬────────────────┘
                                                                     │ clock_in / clock_out
                                                                     │ (fallback: updateShift)
                                                                     ▼
                                                          ┌─────────────────────┐
                                                          │   Factorial API      │
                                                          │ (OAuth2, por cliente)│
                                                          └─────────────────────┘
                                                                     ▲
                                          hourly ── attendance:close-forgotten-shifts
                                          (open_shifts + estimated_times → CloseForgottenShiftJob, tries=3)
```

---

## 3. Modelo de datos (multi-tenencia)

```
Client 1───N FactorialConnection 1───N FactorialEmployee
   │                    │
   │                    └──1───N FactorialLocation
   │
   ├──1───N BiometricProvider ──1───N BiometricSource (dispositivo físico)
   │                              │        │
   │                              │        └──1───N DeviceCommand / DeviceSyncItem / DeviceInventorySnapshot
   │                              └──1───N BiometricUserSync (PIN dispositivo ↔ FactorialEmployee)
   │
   ├──1───N AttendanceLog (marcaje individual, unidad atómica del sistema)
   ├──1───1 ClientAttendanceConfig (mapeo status ZKTeco → check_type, flags de cierre automático)
   └──1───N DeviceSyncBatch (lotes de alta masiva de usuarios en dispositivo)
```

`AttendanceLog` es la tabla central: cada fila es un evento de marcaje (real o sintético) con su `sync_status` (`pending` → `resolved`/`local` → `synced`/`failed`). Tiene una restricción única `(biometric_source_id, employee_code, occurred_at)` que es la defensa principal contra duplicados por reintentos del propio dispositivo (los ZKTeco reenvían el buffer si no reciben ACK a tiempo).

---

## 4. Qué entra y qué sale de los biométricos

### 4.1 Protocolo (ZKTeco push/ADMS sobre HTTP plano)

Rutas bajo `/iclock/*` (`routes/web.php`), **sin CSRF, sin sesión, sin autenticación** — es el protocolo nativo del fabricante, no se puede negociar con el dispositivo. Esto es correcto para el propósito, pero implica que **cualquiera que sepa la URL y el `SN` de un dispositivo real puede inyectar marcajes falsos** — no hay ninguna verificación de origen (ni IP allowlist, ni secreto compartido). Ver hallazgo de seguridad en §8.

| Endpoint | Dirección | Qué transporta |
|---|---|---|
| `GET/POST /iclock/ping` | dispositivo → servidor | heartbeat; actualiza `last_ping_at` |
| `GET/POST /iclock/getrequest` | dispositivo → servidor (poll) → servidor → dispositivo (respuesta) | el dispositivo pregunta si hay comandos pendientes; si trae `INFO=` (firmware, contadores), se captura vía `DeviceInfoService`; la respuesta puede traer un comando en cola (`C:{seq}:{payload}`) |
| `POST /iclock/cdata?table=ATTLOG` | dispositivo → servidor | **marcajes de asistencia** (entrada/salida) — el corazón del sistema |
| `POST /iclock/cdata?table=rtlog` | dispositivo → servidor | marcaje en tiempo real (variante sin buffer, algunos modelos) |
| `POST /iclock/cdata?table=USERINFO` o `user` | dispositivo → servidor | lista de usuarios/PINs dados de alta en el dispositivo |
| `POST /iclock/cdata?table=OPERLOG` | dispositivo → servidor | log de operaciones locales; algunos modelos (SenseFace) reportan altas de usuario aquí en vez de USERINFO |
| `POST /iclock/cdata?table=BIODATA` | dispositivo → servidor | plantilla biométrica cruda (huella/rostro), usada solo para clonar un dispositivo a otro |
| `POST /iclock/devicecmd` | dispositivo → servidor | ACK/NACK de un comando previamente enviado (`ID=`, `Return=`) |
| `GET/POST /iclock/registry` | dispositivo → servidor | registro/activación del equipo; responde un código determinístico (`md5(SN|sintelc)`) |
| `GET/POST /iclock/push` | dispositivo → servidor | negociación de config de push (intervalos, tablas a transmitir) |

**Salida hacia el dispositivo** (servidor → dispositivo, vía la respuesta a `getrequest`): comandos en cola (`DeviceCommand`), típicamente `DATA UPDATE USERINFO ...` (alta de empleado) o `DATA QUERY USERINFO` (pedir inventario). El servidor nunca abre conexión saliente hacia el dispositivo — todo es respuesta a un poll, por diseño del protocolo (los relojes suelen estar detrás de NAT sin puerto expuesto).

### 4.2 Procesamiento de un ATTLOG (`IclockController::handleAttlog`)

1. Rechaza si el dispositivo no existe o no está `active` (`IclockController.php:323-330`).
2. Precarga en memoria: mapeos PIN→empleado Factorial, PINs "locales" (empleados marcados para no sincronizar), `factorial_company_id`, config de check_type, y claves ya existentes en BD (para deduplicar sin N+1).
3. Por cada línea `PIN\tTIMESTAMP\tSTATUS\tVERIFY\tWORKCODE`: parsea, resuelve `check_type` vía `ClientAttendanceConfig::resolveCheckType()` (mapeo configurable por cliente) o el default ZKTeco (0/4=entrada, 1/5=salida, 2/3=descanso).
4. Inserta en bloque (`insertOrIgnore`) — la ignorancia de conflictos es la segunda capa anti-duplicado (la primera es el filtro en memoria).
5. Para los registros que quedaron `resolved` (empleado mapeado + check_type válido), despacha `SyncAttendanceToFactorial` **con delay escalonado de 2s entre cada uno**, ordenados por `occurred_at` ascendente — así, si el dispositivo estuvo offline y manda un lote acumulado, Factorial ve la secuencia cronológica real (salida de ayer antes que entrada de hoy), no el orden de llegada al servidor.

### 4.3 Fallas conocidas del lado biométrico

- **Status inválido intermitente (código de estado `255`).** Confirmado device-side (no es un bug de la app): algunos dispositivos mandan ocasionalmente un `status` no mapeado, cae en `check_type = 'unknown'`, el registro queda `pending` para siempre (nunca se reintenta resolver un `unknown`, solo un `pending` sin empleado) y **ese marcaje del día se pierde silenciosamente**. Afecta activamente a un cliente (Colegio Paidos) al momento de esta auditoría; hay un backfill manual de 40 registros pendiente de aprobación para otro cliente (Outlandish).
- **Sin autenticación de dispositivo.** Cualquier `POST /iclock/cdata?table=ATTLOG&SN=<serial_real>` con body bien formado inserta un marcaje real que se sincroniza a Factorial. No hay secreto por dispositivo ni verificación de IP/red. Es aceptable si los dispositivos están en redes cerradas de cada cliente, pero no hay ninguna capa de defensa si esa asunción falla.
- **`handleRtlog` no tiene el guard de existencia con `insertOrIgnore`** que sí tiene `handleAttlog` a nivel aplicación — depende enteramente de la restricción única de BD y de capturar `QueryException` código `23000`. Funciona, pero es una ruta de código distinta a `handleAttlog` para el mismo problema, con lógica duplicada de resolución de `check_type`/empleado (violación de DRY que ya generó una divergencia sutil: `rtlog` no filtra por `status='active'` en el `BiometricUserSync`, revisar si eso importa).
- **`ImportAttlogDat`** (importación manual desde archivo `.dat`) advierte en su propio comentario que el orden de columnas del archivo (`PIN|DateTime|Verify|InOutMode|WorkCode`) es **distinto** al del push en vivo (`PIN|DateTime|InOutMode|Verify|WorkCode`) — dos formatos parecidos y fáciles de confundir si alguien toca el parser sin releer el comentario.
- **`buildInitResponse`** hardcodea `TimeZone=-6` para *todos* los dispositivos de *todos* los clientes, sin importar la zona horaria real del cliente. Si algún cliente no está en zona horaria México/Centroamérica, el dispositivo puede reportar timestamps corridos.

---

## 5. Qué entra y qué sale de Factorial

### 5.1 Autenticación (`FactorialAuthController` + `FactorialService`)

- **OAuth2 Authorization Code**, un `FactorialConnection` por cliente, con `oauth_client_id`/`oauth_client_secret` propios de cada empresa cliente (no credenciales compartidas de Sintelc) guardados cifrados en `Client`.
- El flujo de `redirect()` usa `Hashids` para no exponer el ID numérico de conexión en la URL, y un `state` aleatorio de 64 chars guardado en caché 10 minutos, ligado al `user_id` que inició el flujo — mitiga CSRF del propio OAuth.
- `callback()` intercambia el `code` por tokens, **decodifica el JWT del access_token a mano** (`base64_decode` de la segunda parte, sin verificar firma) solo para leer `company_id` — no se usa el JWT para autorizar nada, es un atajo aceptable dado que el propio token ya viene de un intercambio autenticado.
- `access_token`/`refresh_token` se guardan con cast `encrypted` de Laravel (clave de la app). Correcto.
- **Refresco de token con lock distribuido** (`Cache::lock`, 30s, bloqueo de hasta 25s): si dos workers de cola necesitan refrescar al mismo tiempo, uno espera y reutiliza el token que refrescó el otro, en vez de generar dos refresh_token consecutivos (el segundo invalidaría al primero en muchos proveedores OAuth). Buen detalle de robustez.
- Si el refresh_token expira/se revoca, la única recuperación es reconectar el OAuth manualmente desde la UI — no hay alerta proactiva cuando eso pasa; el síntoma visible seria un acumulo silencioso de `AttendanceLog.sync_status = failed`.

### 5.2 Endpoints de Factorial consumidos (`FactorialService`)

Todos contra `/api/2026-04-01/resources/...` (versión de API fijada a una fecha concreta — Factorial versiona por fecha, no por número).

| Método | Endpoint Factorial | Uso |
|---|---|---|
| `getEmployees` | `attendance` n/a → `resources/employees/employees` | Catálogo de empleados → `factorial:sync-employees` |
| `getCompany` | `resources/companies/companies` | Nombre/email de la empresa al conectar OAuth |
| `getLocations` | `resources/locations/locations` | Catálogo de sedes → `factorial:sync-locations`, mapeadas a `BiometricSource.factorial_location_id` |
| `getBreakConfigurations` | `resources/attendance/break_configurations` | Definido pero **sin ningún llamador en el código** (dead code / preparado para una feature no terminada) |
| `getShifts` | `resources/attendance/shifts` (paginado, tope 20 páginas / 2000 turnos) | Buscar turno abierto/cerrado para overwrite y corrección |
| `clockIn` / `clockOut` | `.../shifts/clock_in` / `clock_out` | **Camino feliz** del sync de un marcaje |
| `toggleClock` | `.../shifts/toggle_clock` | Implementado pero **no usado actualmente** — se probó y revirtió (ver §7.4) |
| `createShift` / `deleteShift` | `.../shifts` | Definidos, **sin llamadores** — API preparada, no usada |
| `updateShift` | `PUT .../shifts/{id}` | Fallback de overwrite y de corrección de cierre anticipado; también usado por el cierre de turnos olvidados |
| `getOpenShifts` | `resources/attendance/open_shifts` (filtro server-side por `employee_ids`, troceado en lotes de 80) | Base del mecanismo de cierre de turnos olvidados |
| `getEstimatedTimes` | `resources/attendance/estimated_times` | Horas planificadas por empleado/día (viene del horario/contrato que el cliente ya cargó en Factorial) |

**Nota de diseño explícita en el código:** el query string se construye a mano (`buildQuery()`) en formato Rails (`campo[]=valor`) en vez del default de PHP (`campo[0]=valor`), porque la API de Factorial no acepta el segundo formato y devuelve errores internos confusos. Es un detalle no obvio, bien documentado en el comentario — cualquier cambio futuro al método de query debe preservar esto.

### 5.3 Salida hacia Factorial: el sync de un marcaje (`SyncAttendanceToFactorial`)

Job de **un solo intento** (`tries=1`) — decisión deliberada: reintentar automáticamente un `clock_in`/`clock_out` que en realidad sí llegó a Factorial (pero falló al confirmar) generaría un turno duplicado. En vez de reintentos ciegos, el job hace su propia verificación de idempotencia contra la API antes de darse por vencido.

Cascada de intentos, en orden:

1. **Directo:** `clockIn()` para `check_in`/`break_out`, `clockOut()` para `check_out`/`break_in`. Si Factorial responde sin `id` de turno, se verifica si igualmente lo creó (`findMatchingShift`) antes de fallar.
2. **Si falla con excepción HTTP:** primero revisa de nuevo idempotencia (`findMatchingShift`) — cubre el caso de que el job haya creado el turno en un run anterior pero muerto antes de guardar en la BD local.
3. **Overwrite:** busca un turno **abierto** ese mismo día (`findOpenShift`) y lo sobreescribe con `updateShift()`, sea cual sea su `in_source` (web, móvil, geolocalización, o ya creado por API) — **el biométrico siempre tiene prioridad**, con una excepción: si el turno ya trae la marca `Editado por biométrico SFT: {check_type}` en `observations`, no se vuelve a tocar (evita que un doble-marcaje físico en el reloj pise el primero).
4. **Corrección de cierre anticipado** (agregado el 2026-08-24, commit `d79bcda`, tercer fallback): si no hay turno abierto, busca uno **cerrado** ese mismo día (o el anterior, para turnos nocturnos) cuyo `clock_out` sea anterior a la hora real del marcaje — indica que Factorial cerró el turno antes de tiempo (por horario/contrato configurado, o un break mal cerrado) y corrige el `clock_out`/`clock_in` a la hora real.
5. Si nada de lo anterior aplica: `sync_status = failed`, `sync_error` con el mensaje extraído de Factorial (dos formatos distintos de error observados en producción: `errors.exception` y `errors.errors` — este segundo visto en pruebas reales de agosto 2026 como *"Forbidden by Attendance::EmployeePolicy::ClockInOut"*, un rechazo de política/permisos, no de validación).

**Limitación reconocida en el propio código:** tanto `findOpenShift` como `findClosedShiftToCorrect` solo miran **la fecha exacta** del marcaje (con una extensión ad-hoc al día anterior solo para turnos nocturnos antes de las 6am). Un empleado con un turno abierto de *varios* días atrás (el caso real de ~24h reportado en agosto 2026 que motivó `factorial:list-open-shifts`) no lo encuentra ninguno de los dos fallbacks — el marcaje termina en `failed` con "sin turno abierto para sobreescribir", aunque el turno viejo exista y sea la causa real del problema.

### 5.4 Entrada desde Factorial: catálogos

- **`factorial:sync-employees`** (manual, ya no hay scheduler para esto — nota explícita en `routes/console.php`: "el mapeo de empleados es ahora manual desde la UI"): paginación cursor-based (`after_id`/`end_cursor`), tope duro de 20 páginas (2000 empleados), `upsert` por `(factorial_connection_id, factorial_id)`.
- **`factorial:sync-locations`**: sin paginación (asume que la respuesta cabe en una sola página — riesgo si un cliente tiene muchas sedes).
- **`SyncFactorialConnection`** (job, disparado desde la UI de conexión): hace lo mismo que los dos comandos de arriba combinados, con reporte de progreso en caché (`factorial_sync_result:{id}`) para que la UI pueda pollear el estado — **código duplicado** casi línea por línea contra `SyncFactorialEmployees`/`SyncFactorialLocations` (mismo `upsert`, misma forma de paginar). Tres implementaciones del mismo "traer empleados de Factorial" es deuda técnica concreta: un fix de paginación o de mapeo de campos aplicado en un solo lugar deja los otros dos desactualizados.

---

## 6. Cierre de turnos olvidados / `checkin_only` — mecanismo completo

Corre cada hora (`Schedule::command('attendance:close-forgotten-shifts')->hourly()`, activo desde 2026-08-17; antes de esa fecha `routes/console.php` no tenía nada programado).

**Por qué existe:** un turno que queda abierto en Factorial bloquea el *siguiente* `check_in` de ese empleado con un error `open_shift` hasta que alguien lo note y lo cierre a mano. Para clientes `checkin_only` (nunca mandan salida) esto pasaría *todos los días*; para clientes normales es una red de seguridad opcional ante el olvido ocasional.

**Cómo decide qué cerrar** (`CloseForgottenShifts::processConnection`):
1. Trae turnos abiertos vía el endpoint nativo `getOpenShifts` (filtrado server-side por `employee_ids`, **nunca** el patrón viejo de filtrar `clock_out === null` en PHP sobre todos los turnos — restricción explícita en `CLAUDE.md` para no reintroducir un bug ya corregido).
2. Pide `getEstimatedTimes` para el rango de fechas cubierto, de una sola vez por conexión.
3. Si `expected_minutes > 0`: hora de cierre = `clock_in + expected_minutes` (sin margen). Si es `0` o no hay dato: fallback a turno fijo de 8h — **tratando "día de descanso real" igual que "sin contrato configurado"**, porque el campo no permite distinguir ambos casos (hueco conocido, sin resolver).
4. Umbral para actuar = hora de cierre + 4h de margen. Si `now < umbral`, no toca el turno todavía.
5. Despacha `CloseForgottenShiftJob` (`tries=3`, backoff 30s/2min/5min — a diferencia del sync normal, aquí sí conviene reintentar: perder este job silenciosamente reintroduce el bloqueo `open_shift`).

**Qué hace el job:** reconfirma que el turno sigue abierto (idempotencia — pudo haberse cerrado con un marcaje real entre la detección y la ejecución), llama `updateShift()` con el `clock_out` calculado y una nota en `observations`, e inserta un `AttendanceLog` sintético (`check_type=check_out`, `sync_status=synced`) contra un `BiometricSource` virtual **por cliente** (`serial_number = AUTOCLOSE-{client_id}`, `status='virtual'`) para que el cierre sea visible en la pantalla de registros del cliente, con nota bajo el badge (ámbar y en negrita si `checkin_only=false`, gris si es rutina).

**El hueco explícito y aún abierto:** para `checkin_only=false` (una excepción real, no rutina), la intención declarada es también generar una `edit_timesheet_requests` en Factorial para que aparezca en el flujo nativo de revisión del cliente — no se implementó porque no se pudo determinar el valor exacto del enum `request_type` que espera ese endpoint (la documentación de Factorial no bajó al detalle de campo, y probar a ciegas contra la API de un cliente real se consideró irresponsable). Mientras tanto la única señal es un `Log::warning` con la etiqueta *"REQUIERE REVISIÓN"* y la nota visible en pantalla — **si nadie entra a esa pantalla, la excepción pasa desapercibida**.

---

## 7. Gestión de dispositivos (subsistema secundario pero real)

Media docena de servicios pequeños en `app/Services/` orquestan el ciclo de vida de un dispositivo más allá de recibir marcajes:

- **`DeviceProtocolResolver`** — decide el "perfil" de protocolo de un dispositivo (`attendance_push`, `senseface_push`, `legacy_attendance_aggregate`) por: perfil fijado manualmente > nombre de dispositivo reportado > prefijo de firmware > default. Config centralizada en `config/biometric-protocols.php`, documentado en `docs/biometric-protocol-routing.md` con la regla explícita de "no agregar condiciones de modelo en controladores".
- **`DeviceInfoService`** — parsea el parámetro `INFO=` de `getrequest` (firmware, contador de usuarios/huellas/rostros reportado por el propio dispositivo), y dispara verificación de lotes pendientes cuando el contador confirma que el dispositivo ya tiene los altas esperadas.
- **`DeviceAggregateVerificationService`** / **`DeviceAssignmentVerificationService`** — dos métodos de confirmar que un alta de usuario "pegó" en el dispositivo: `detailed` (el propio dispositivo lista el PIN vía USERINFO/OPERLOG) vs `aggregate_info` (solo hay un contador confiable, se confirma un lote completo si todos sus comandos fueron `acknowledged` Y el `INFO` posterior alcanza línea base + altas del lote).
- **`DeviceSyncBatchService`** — orquesta un lote de altas masivas (usado por `biometric:push-users` y por la UI), materializa `DeviceCommand` por PIN a agregar.
- **`DeviceReconciliationService`**, **`DeviceOnboardingService`**, **`DeviceCommandLifecycleService`**, **`DeviceEmployeeRefreshService`**, **`DeviceAssignmentMaterializer`** — ciclo de alta de dispositivo nuevo, materialización de qué empleados deben estar en qué dispositivo, y refresco dirigido cuando cambia un empleado puntual.

Este subsistema está bien separado en servicios de responsabilidad única, con nombres autoexplicativos — es la parte del código con mejor factorización de todo el repo. No se auditó línea por línea (no es el foco pedido: biométrico↔Factorial), pero su diseño no mostró señales de alarma al revisarlo.

---

## 8. Seguridad

- **`/iclock/*` sin autenticación** (necesario por protocolo, ver §4.3) — el único control es que el `SN` debe existir y estar `active`/asignado a un cliente en la BD. Sin allowlist de IP, sin secreto por dispositivo. Riesgo real si algún dispositivo queda expuesto a Internet en vez de solo a la red del cliente.
- **CSRF exento explícitamente para `iclock/*`** (`bootstrap/app.php`) — correcto y necesario, el dispositivo no puede generar tokens CSRF.
- **Middleware `admin`** (`EnsureAdmin`) separa rutas de administración (`dashboard`, `clients`, `employees`, `devices`) de las rutas de cliente (`mis-registros`, `mis-dispositivos`, `mis-empleados`) — un `User` tiene `role` (`admin`/`client`) y `client_id` opcional; el aislamiento se hace en cada ruta/query, no hay un scope global automático (policy o global scope) — **cada nuevo query a un modelo con `client_id` depende de que el desarrollador recuerde filtrar por el cliente correcto**. Es el patrón de riesgo más común en apps multi-tenant: un query livewire que olvida el `where('client_id', ...)` filtra datos de otro cliente. Existe un test dedicado (`MultiTenantAuthorizationTest.php`), señal de que el equipo ya es consciente del riesgo, pero un test no cubre cada query nueva que se agregue después.
- **Secretos cifrados:** `access_token`/`refresh_token` de Factorial y `oauth_client_secret` del cliente usan cast `encrypted` de Laravel — bien. Depende de que `APP_KEY` esté protegida y respaldada; si se pierde, **todas** las conexiones OAuth quedan irrecuperables (hay que reconectar manualmente cada una).
- **JWT decodificado sin verificar firma** (`FactorialAuthController::callback`, solo para leer `company_id`) — aceptable porque el JWT vino de un intercambio ya autenticado por HTTPS+client_secret, no se usa para autorizar nada; documentarlo explícitamente evitaría que alguien lo "arregle" mal entendiendo que es una vulnerabilidad cuando no lo es en este contexto.

---

## 9. Frontend (Livewire Volt)

Todo en `resources/views/livewire/`, sin controladores dedicados:

| Componente | Función |
|---|---|
| `devices/device-manager` | Lista de dispositivos, import CSV de usuarios, cola de comandos |
| `devices/device-onboarding` | Flujo guiado de alta de un dispositivo nuevo |
| `clients/client-manager` | Alta/gestión de empresas cliente |
| `clients/client-profile` | Edición inline de datos de cliente + gestor de conexión embebido |
| `clients/attendance-records` | Pantalla de registros de asistencia por cliente — aquí se renderiza la nota de cierre automático |
| `employees/employee-sync-manager` | Mapeo PIN↔empleado Factorial (el componente más grande, ~1850 líneas) — **contiene el gap de `status='virtual'` sin filtrar (§1, hallazgo #1)** |
| `connections/connection-manager` | Alta/estado de conexiones OAuth Factorial, embebible por cliente |
| `dashboard/attendance-stats` | Métricas de sync con donas Chart.js, caché 30–300s |
| `dashboard/device-status` | Salud de dispositivos (online/offline, última vez visto) |

---

## 10. Colas, caché y scheduler

- **Cola:** driver `database` (tabla `jobs`), un único worker vía Supervisor (`queue:work --sleep=1 --tries=1` como *default* del proceso — pero cada Job define su propio `$tries`, que sobreescribe el flag de CLI). Sin Horizon, sin métricas de cola más allá de mirar la tabla `jobs`/`failed_jobs` a mano.
- **Caché:** driver `database`. Usada para: locks de refresco de token OAuth, resultado de sync de conexión (polling desde UI), badges de navegación (`nav.unassigned_devices`, `nav.unresolved_employees`, 60s), stats de dashboard (30–300s).
- **Scheduler:** depende de una entrada de **crontab del sistema operativo** (`* * * * * php artisan schedule:run`) que *no* está versionada en el repo ni se recrea sola en un servidor nuevo — es infraestructura fuera del control de git. Actualmente solo corre `attendance:close-forgotten-shifts` cada hora.

**Riesgo de escala con un solo worker:** con 28 dispositivos y 11 clientes, el volumen actual probablemente es manejable, pero un solo worker de cola serializa *todo* — sync de asistencia, sync de conexión Factorial (job puede tardar por paginación de hasta 2000 empleados), y cierre de turnos olvidados compiten por el mismo proceso. Un pico de marcajes (hora de entrada de una empresa grande) puede retrasar el procesamiento de otro cliente.

---

## 11. Deuda técnica y limpieza pendiente

1. **`app/Jobs_old/` y `app/Services_old/`** (sin versionar, 10 archivos) — copias pre-refactor de clases reales, mismo namespace/nombre. No rompen el build (Composer las descarta por no ser PSR-4-compliant al hacer `dump-autoload -o`, verificado en esta auditoría), pero son confusión pura: el diff contra las versiones vigentes muestra que carecen del fallback de "corrección de cierre anticipado" agregado el mismo día de esta auditoría. **Recomendación: borrarlas** (o si son respaldo intencional, moverlas fuera del árbol `app/` y fuera del namespace `App\`).
2. **Tres implementaciones de "traer empleados/ubicaciones de Factorial"** (`SyncFactorialEmployees`, `SyncFactorialConnection`, y la lógica de `getEmployees` repetida) — mismo `upsert`, mismo shape de fila, código pegado tres veces. Un cambio de campo (por ejemplo, si Factorial agrega un campo a sincronizar) requiere tocar los tres lugares.
3. **`getBreakConfigurations`, `createShift`, `deleteShift`, `toggleClock`** en `FactorialService` no tienen ningún llamador actual — o son preparación para una feature futura, o son restos de la reversión de `toggle_clock`. Vale la pena decidir explícitamente cuál de las dos cosas es, porque código muerto en un servicio de integración externa es fácil de confundir con "esto sí se usa en algún flujo que no vi".
4. **`handleAttlog` y `handleRtlog`** duplican casi toda la lógica de resolución de empleado/check_type con variaciones sutiles (uno usa `insertOrIgnore` en bloque, el otro `create()` + catch de `QueryException`; uno filtra por PINs locales, el otro no). Candidato claro a extraer un método/servicio compartido.
5. **Zona horaria hardcodeada** (`TimeZone=-6` en `buildInitResponse`) para todos los clientes — funciona hoy porque (aparentemente) todos los clientes actuales están en la misma zona, pero es una bomba de tiempo si se onboardea un cliente fuera de México/Centroamérica.

---

## 12. Fallas por área (resumen priorizado)

### Alta prioridad
- **`employee-sync-manager.blade.php`** no excluye `status='virtual'` en ninguna de sus 13 consultas a `BiometricSource` — para clientes con cierre automático activo (Outlandish, ADACA según memoria de proyecto), el dispositivo sintético aparece seleccionable en la UI de mapeo de empleados. Corregir agregando `->where('status', '!=', 'virtual')` en cada query, igual que ya se hizo en `device-manager`, `attendance-stats`, `client-manager` y `device-status`.
- **Sin `edit_timesheet_requests`** para turnos olvidados reales — la excepción vive solo en logs y una nota visual; no llega al flujo de revisión nativo de Factorial que el cliente ya conoce y usa.
- **`findOpenShift`/`findClosedShiftToCorrect` solo miran la fecha exacta (±1 día para nocturnos)** — un turno abierto de varios días atrás no lo detecta ningún fallback; el marcaje termina en `failed` en vez de corregirse, y el propio `factorial:list-open-shifts` existe justamente porque este límite ya causó un incidente real.
- **Status ZKTeco `255` intermitente** pierde el marcaje del día completo (queda `pending` con `check_type=unknown` para siempre) — afecta a un cliente activamente hoy.

### Media prioridad
- **`expected_minutes == 0` tratado igual que "sin horario"** — genera turnos de 8h fantasma en días de descanso real configurados en Factorial.
- **Un solo worker de cola** sin aislamiento entre tipos de job — un pico de un cliente puede retrasar a otro.
- **Sin allowlist/secreto para `/iclock/*`** — cualquiera que conozca un `SN` real puede inyectar marcajes.
- **Zona horaria fija `-6`** para todos los dispositivos.
- **Código duplicado de sync de empleados** (3 implementaciones) y de **procesamiento ATTLOG/rtlog** (2 rutas paralelas).

### Baja prioridad / limpieza
- `Jobs_old`/`Services_old` sin versionar — borrar o aislar del namespace `App\`.
- Métodos sin llamador en `FactorialService` (`createShift`, `deleteShift`, `toggleClock`, `getBreakConfigurations`) — decidir si son preparación a futuro o restos, documentarlo.
- `SyncFactorialLocations` no pagina (asume que todas las ubicaciones caben en una página).

---

## 13. Recomendaciones (orden sugerido)

1. **Fix inmediato y barato:** agregar el filtro `status != 'virtual'` en `employee-sync-manager.blade.php` — mismo patrón ya aplicado en otros 4 archivos, bajo riesgo, alto impacto para los clientes ya activos con cierre automático.
2. **Decidir y ejecutar sobre `Jobs_old`/`Services_old`:** o se borran, o se documenta por qué existen y se sacan del árbol `app/`.
3. **Ampliar la ventana de búsqueda de turno abierto/cerrado** en `SyncAttendanceToFactorial` más allá de la fecha exacta (con un tope razonable, p. ej. 3–5 días), para cubrir el caso real ya visto de turnos abandonados varios días.
4. **Resolver el enum de `edit_timesheet_requests`** con Factorial (soporte del proveedor, no prueba a ciegas) para cerrar el hueco de visibilidad de turnos olvidados reales.
5. **Investigar el origen del status `255`** con el fabricante/proveedor del dispositivo afectado, o como mitigación de corto plazo, tratar `status` desconocido como `check_type` inferido por alternancia (si el último marcaje del empleado ese día fue `check_in`, asumir `check_out`) en vez de descartarlo silenciosamente.
6. **Unificar las tres rutas de "traer empleados de Factorial"** en un solo servicio compartido por el comando y el job.
7. **Evaluar mover la cola a Redis / separar colas por tipo** (`asistencia`, `catalogos`, `cierre-turnos`) si el volumen de clientes sigue creciendo — hoy con 11 clientes probablemente no es urgente, pero es la primera pared de escala que se va a golpear.

---

## 14. Apéndice

### 14.1 Comandos Artisan relevantes
```
php artisan attendance:resolve-pending          # resuelve pending usando mapeos existentes
php artisan attendance:resolve {sourceId?}      # resuelve por dispositivo específico
php artisan attendance:close-forgotten-shifts   # cierre de turnos olvidados (scheduler, hourly)
php artisan attlog:import-dat {file} --sn=      # import manual de .dat históricos
php artisan factorial:sync-employees {id?}
php artisan factorial:sync-locations {id=1}
php artisan factorial:list-open-shifts --client= --stale-only   # diagnóstico pre-deploy
php artisan biometric:push-users {sourceId}
```

### 14.2 Variables de entorno de negocio (`config/services.php`)
```
FACTORIAL_BASE_URL       (default https://api.factorialhr.com)
FACTORIAL_CLIENT_ID / FACTORIAL_CLIENT_SECRET   # solo fallback global; en producción cada Client tiene su propio oauth_client_id/secret
FACTORIAL_REDIRECT_URI
```

### 14.3 Cobertura de tests existente
`tests/Feature/`: `CloseForgottenShiftJobTest`, `CloseForgottenShiftsCommandTest`, `SyncAttendanceToFactorialTest`, `IclockAttendanceTest`, `ListFactorialOpenShiftsTest`, `FactorialOAuthSecurityTest`, `MultiTenantAuthorizationTest`, `DeviceOnboardingFoundationTest`, `DeviceSyncBatchTest`, `DeviceEmployeeRefreshTest` — buena cobertura de los mecanismos críticos recientes (cierre de turnos, seguridad OAuth, multi-tenencia). **No hay test para el nuevo fallback de "corrección de cierre anticipado"** (`findClosedShiftToCorrect`/`correctClosedShift`, agregado el mismo día de esta auditoría) ni para `employee-sync-manager` (el componente más grande y con el gap activo de `status='virtual'`).

### 14.4 Escala real en producción (al momento de esta auditoría)
- 11 `Client`
- 28 `BiometricSource` (excluyendo el/los virtual(es) de auto-cierre)
- 12 `FactorialConnection` con `access_token` activo
