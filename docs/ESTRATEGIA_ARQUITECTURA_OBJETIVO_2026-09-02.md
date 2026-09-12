# Estrategia de arquitectura objetivo — Sintelc FlowTime

**Fecha:** 2026-09-02
**Autor:** ejercicio de diseño solicitado al tomar el proyecto
**Alcance:** cómo habría diseñado el sistema completo desde cero —base de datos, servidor, borde biométrico ZKTeco, canal a Factorial, seguridad, operación— y cómo llegar ahí desde el sistema vivo sin romper nómina.

> **Qué es este documento.** Un ejercicio de arquitectura contrafactual con un propósito práctico: no sirve para juzgar lo construido, sirve para fijar el **norte**. Cuando tomas un sistema en producción, la pregunta útil no es "¿está bien hecho?" sino "¿hacia dónde lo muevo, y en qué orden, para que cada paso pague solo?". La sección 12 es la que se ejecuta; las 11 anteriores existen para justificarla.
>
> **Qué no es.** No es un rediseño para tirar y reescribir. La recomendación explícita es **no reescribir**: el stack es correcto, el equipo lo conoce, y hay 11 clientes cobrando. Todo lo que sigue es evolutivo.
>
> **Documentos hermanos.** Este se apoya en `AUDITORIA_ARQUITECTURA_2026-08-24.md` (qué existe y qué falla) y `DIAGNOSTICO_Y_BUENAS_PRACTICAS_2026-09-01.md` (rendimiento, seguridad y contención inmediata). No los repite: los usa como evidencia y los ordena bajo una tesis única.

---

## 1. Los requerimientos, reconstruidos

No hay un documento de requerimientos formal en el repositorio. Lo que sigue está reconstruido a partir del código, las migraciones, el historial de 450 commits (marzo–septiembre 2026), los tres documentos de `docs/` y las minutas recogidas en el expediente MLA. Es la base sobre la que se diseña: **si algo de esta lista está mal, el diseño de abajo cambia.**

### 1.1 Requerimientos funcionales

| # | Requerimiento | Evidencia |
|---|---|---|
| RF1 | Recibir marcajes de relojes ZKTeco **sin instalar agente en sitio** y con los equipos detrás de NAT | Protocolo push/ADMS, el equipo hace *polling*; el servidor nunca abre conexión saliente |
| RF2 | **Multi-tenant**: un servidor, N empresas cliente, datos aislados, cada una con su propia cuenta de Factorial | `Client` → `FactorialConnection`, OAuth por cliente con credenciales propias |
| RF3 | Convertir marcajes en **turnos de asistencia en Factorial** | `clockIn`/`clockOut`, núcleo del producto |
| RF4 | **Mapear identidad**: PIN del dispositivo ↔ empleado de Factorial; soportar empleados "locales" que no van a Factorial | `BiometricUserSync`, soporte de empleado local (jul 2026) |
| RF5 | **Administrar el dispositivo remotamente**: alta de usuarios, cola de comandos, inventario, clonado de plantillas biométricas | `DeviceCommand`, `DeviceSyncBatch`, `BIODATA` |
| RF6 | Sincronizar **catálogos** desde Factorial: empleados y ubicaciones/sedes | `factorial:sync-employees`, `factorial:sync-locations` |
| RF7 | **Configuración por cliente**: mapeo de código de estado del reloj → tipo de marcaje, modo solo-entradas, cierre automático | `ClientAttendanceConfig` |
| RF8 | **Cerrar turnos olvidados** y atender clientes que nunca marcan salida | `attendance:close-forgotten-shifts`, vivo desde 2026-08-17 |
| RF9 | **UI de operación** para Sintelc y **portal para el cliente final**, con roles separados | `EnsureAdmin`, rutas `mis-registros` / `mis-dispositivos` / `mis-empleados` |
| RF10 | **Reporte y exportación** de asistencia a Excel | Feature declarada en README |
| RF11 | **Importación manual / backfill** de históricos | `attlog:import-dat` |
| RF12 | *(nuevo, en curso)* Admitir **fuentes distintas al reloj**: cámaras de reconocimiento facial Provision, en modo solo-entradas | Expediente MLA, reunión 2026-08-31 |

### 1.2 Requerimientos no funcionales, con los números reales

Medidos el 2026-09-01 en producción. Un requerimiento no funcional sin número es una opinión.

| Dimensión | Estado medido | Objetivo de diseño |
|---|---|---|
| **Volumen de tráfico** | 37.018 req/día; **90,7% es polling** (`getrequest`, 33.570/día); pico 464 req/min | Sostener 5× sin cambiar de instancia |
| **Volumen de negocio** | ~380–400 marcajes/día; 14.501 registros históricos; 11 clientes, 28 dispositivos, ~1.479 empleados | Sostener 50 clientes / 150 dispositivos |
| **Latencia marcaje → Factorial** | Hoy: 1–13 min en lote normal; **20 min con 3 equipos recuperándose** | < 60 s en operación normal; < 5 min en recuperación de lote |
| **Corrección del dato** | **27% de fallo el 2026-09-01** (102 de 383); 451 `failed` y 1.278 `pending` acumulados; el `pending` más antiguo lleva 3 meses | **< 1% de fallo terminal.** Es el requerimiento rector |
| **Disponibilidad** | Instancia única, sin HA, sin balanceo | Los relojes bufferean: una caída corta es tolerable **si nada se pierde al recuperarse**. Se prioriza durabilidad sobre uptime |
| **Recuperabilidad** | **Sin respaldo automatizado de base de datos** | RPO ≤ 24 h, RTO ≤ 4 h, con restauración probada |
| **Observabilidad** | 95 MB de log en un día, con 7.021 errores reales enterrados bajo el ruido; 25 respuestas HTTP 500 que nadie vio | Alertas por síntoma de negocio, no por revisión manual de pantallas |
| **Costo** | EC2 t3.micro (2 vCPU, 3,7 GB, 13 GB disco, 67% usado) | Restricción de primer orden, no un detalle |
| **Mantenibilidad** | Equipo de 1–2 personas; **el proyecto está cambiando de manos ahora mismo** | El diseño tiene que ser explicable en una tarde |

> **El requerimiento que manda sobre todos los demás:** esto alimenta **nómina**. Un marcaje perdido o duplicado tiene consecuencia económica y legal para el cliente final y para el empleado. Cuando corrección y rendimiento entran en conflicto, gana corrección. Cuando corrección y elegancia entran en conflicto, gana corrección.

### 1.3 Restricciones no negociables

Estas no se diseñan, se aceptan. Todo lo demás se acomoda alrededor.

1. **El protocolo ZKTeco es del fabricante.** HTTP plano, sin autenticación, sin CSRF, iniciado por el equipo mediante polling. No se puede negociar con el reloj. Cualquier seguridad tiene que estar en el canal o en el borde, nunca en el protocolo.
2. **El reloj reenvía su buffer si no recibe ACK a tiempo.** La idempotencia no es una mejora: es un requisito de protocolo.
3. **La API de Factorial impone su modelo.** Versionada por fecha (`2026-04-01`), query en formato Rails (`campo[]=`), semántica de turnos sensible al orden, y un turno abierto **bloquea** el siguiente `clock_in` con `open_shift`. Su límite de peticiones no está documentado del lado del proyecto.
4. **`toggle_clock` está vetado por evidencia.** Se implementó dos veces (`74bbc6a`, `4124e52`) y se revirtió (`3f6e47e`) porque corrompió turnos y horas en producción, aun siendo la recomendación oficial de su soporte. Cualquier diseño que lo reintroduzca tiene que explicar primero por qué falló.
5. **Presupuesto y equipo pequeños.** Nada de microservicios, nada de Kubernetes, nada que requiera un ingeniero de plataforma dedicado.
6. **El sistema está vivo con clientes que pagan.** No hay ventana para reescribir.

---

## 2. El diagnóstico de fondo: es un error de modelo, no de código

Antes de proponer arquitectura hay que nombrar el problema real. Las 451 fallas acumuladas, la cascada de cuatro fallbacks, el mecanismo separado de cierre de turnos y el cuello de botella del worker único **no son cinco problemas**. Son un solo problema visto desde cinco ángulos.

### 2.1 La tesis

> El sistema modela la asistencia como **una secuencia de órdenes imperativas a un sistema remoto**, cuando debería modelarla como **un registro local de hechos, más un proceso que hace converger al sistema remoto hacia ese registro**.

Dicho en el vocabulario del dominio: hoy FlowTime *le dice a Factorial qué hacer* ("marca entrada", "marca salida") y espera que salga bien. Debería *saber cuál es la jornada real del empleado* y encargarse de que Factorial acabe reflejándola, reintentando cuantas veces haga falta.

La diferencia no es filosófica. Es la diferencia entre una operación que **no se puede reintentar sin riesgo de duplicar** y una que **se puede reintentar siempre**.

### 2.2 Los siete síntomas que salen del mismo error

| Síntoma observado | De dónde sale realmente |
|---|---|
| `tries = 1`, sin reintentos, y un `HTTP request failed` transitorio pierde el marcaje **para siempre** | Un comando no es idempotente: reintentar `clockIn` puede duplicar el turno. La decisión de no reintentar es **correcta dado el modelo**; el modelo es el que está mal |
| Cascada de **4 fallbacks** en `SyncAttendanceToFactorial` (directo → verificación de idempotencia → sobreescribir turno abierto → corregir turno cerrado antes de tiempo) | Cada fallback es una **reconstrucción ad-hoc del estado deseado** en el momento del fallo. Si el estado deseado se calculara siempre, no habría cascada: habría un solo camino |
| La marca `Editado por biométrico SFT` escrita en el campo `observations` de Factorial | Se está usando **un campo de texto libre remoto como almacén de estado local**. Es la causa directa de 140 fallas: `Turno ya editado por biométrico SFT para check_in` |
| `CloseForgottenShifts` es un **segundo mecanismo de sincronización paralelo**, con su propia lógica, su propio job y su propio dispositivo virtual sintético | Si existiera un reconciliador, "cerrar un turno olvidado" no sería un mecanismo: sería **una regla más** que cambia el estado deseado de la jornada |
| `findOpenShift` solo mira la fecha exacta (±1 día para nocturnos), y un turno abandonado hace días no lo encuentra nadie | Se **busca** el objeto remoto por fecha porque no se **sabe** cuál es. Un vínculo local ↔ remoto guardado elimina la búsqueda y el límite de ventana |
| El `status = 255` intermitente convierte el marcaje en `check_type = unknown` y **lo pierde en silencio para siempre** | El tipo de marcaje se **decide y congela en el momento de la ingesta**. Si se derivara después, un evento con estado inválido sería recuperable con solo reinterpretar |
| Retardo global acumulativo de 2 s + un solo worker: un lote de 400 registros tarda 13,3 min; tres equipos a la vez, 20 min, y **bloquean a todos los demás clientes** | El orden cronológico se garantiza **operativamente** (serializando todo) porque la API remota es sensible al orden. Si el orden fuera una propiedad del cálculo local, la serialización sobraría |

Ese último es el más elegante y el que mejor ilustra la tesis: **el problema de rendimiento no se arregla, se disuelve.** No hace falta optimizar el retardo si el orden deja de depender de cuándo llegan los jobs.

### 2.3 Qué NO está mal

Un arquitecto que llega y quema el trabajo previo se equivoca dos veces. Esto hay que **preservarlo explícitamente** (detalle completo en §11):

- La idempotencia por clave única `(biometric_source_id, employee_code, occurred_at)`.
- El razonamiento detrás de `tries = 1` — es la decisión correcta dentro del modelo actual.
- El lock distribuido en el refresco de token OAuth.
- El cifrado de tokens y secretos OAuth.
- El `DeviceProtocolResolver` guiado por configuración, con la regla "no metas condiciones de modelo en los controladores".
- La detección de día de descanso real vía el campo `source` de `getEstimatedTimes`.
- La honestidad de la documentación interna: registra los huecos en vez de esconderlos. Es lo que hace posible este documento.

---

## 3. La arquitectura objetivo

### 3.1 Vista de conjunto: cuatro capas, una sola dirección de dependencia

```
   FUENTES                    ┌──────────────────────────────────────────────┐
   ────────                   │  CAPA 0 · BORDE DE INGESTA                    │
   Reloj ZKTeco  ──────────►  │  Autentica la fuente. Persiste el byte crudo.  │
   Cámara Provision ───────►  │  Responde rápido. No entiende de negocio.      │
   Import .dat / CSV ──────►  │  → ingest_events (append-only, inmutable)      │
   Alta manual (UI) ───────►  └───────────────────────┬──────────────────────┘
                                                      │
                              ┌───────────────────────▼──────────────────────┐
                              │  CAPA 1 · IDENTIDAD                           │
                              │  ¿Quién es esta persona?                      │
                              │  identificador de fuente → persona canónica   │
                              │  Lo no resuelto va a triaje CON SLA           │
                              └───────────────────────┬──────────────────────┘
                                                      │
                              ┌───────────────────────▼──────────────────────┐
                              │  CAPA 2 · JORNADA   ← el corazón del sistema  │
                              │  Función PURA:                                │
                              │    eventos + reglas + correcciones            │
                              │      → intervalos de la jornada               │
                              │  Esta capa es la VERDAD. Factorial no lo es.  │
                              └───────────────────────┬──────────────────────┘
                                                      │
                              ┌───────────────────────▼──────────────────────┐
                              │  CAPA 3 · PROYECCIÓN                          │
                              │  Estado deseado vs. estado remoto → diff      │
                              │  Converge. Siempre reintentable.              │
                              │  Vínculo guardado jornada ↔ turno remoto      │
                              └───────────────────────┬──────────────────────┘
                                                      │
                                                      ▼
                                              ┌──────────────┐
                                              │ Factorial API │
                                              └──────────────┘

   CAPA 4 · PLATAFORMA  (transversal)
   colas por criticidad · caché y locks · observabilidad · respaldos · despliegue
```

**Las dos reglas que sostienen todo el diseño:**

1. **La dependencia va en una sola dirección.** La capa 2 no sabe que Factorial existe. La capa 0 no sabe qué es un turno. Un cambio en la API de Factorial toca la capa 3 y nada más; una fuente nueva toca la capa 0 y nada más. Hoy la semántica de ZKTeco está filtrada hasta la base de datos, y por eso cerrar un turno automáticamente obliga a inventar un **dispositivo físico falso**.
2. **La verdad vive aquí, no en Factorial.** Factorial es una proyección, igual que la pantalla de registros o el Excel exportado. Esta sola decisión convierte cada operación remota en reintentable.

### 3.2 Capa 0 — El borde de ingesta

**Responsabilidad:** autenticar la fuente, persistir el evento crudo tal como llegó, responder rápido. Nada más.

**Contrato de entrada (idéntico para toda fuente):**

```
ingest_events
  id
  client_id
  source_id                 -- FK a sources (reloj, cámara, importación, UI)
  source_kind               -- zkteco_attlog | zkteco_rtlog | provision_event
                            -- | dat_import | manual | system
  external_key              -- clave natural de la fuente, para deduplicar
  occurred_at               -- hora del hecho, en UTC
  occurred_at_tz            -- zona horaria declarada por la fuente
  raw_payload               -- JSON o texto crudo, TAL COMO LLEGÓ
  received_at
  interpretation_version    -- qué versión de reglas lo interpretó
  interpreted_at            -- NULL = pendiente de interpretar
  UNIQUE (source_id, external_key)
```

**Las cinco decisiones de esta capa:**

**(a) El evento crudo es inmutable y se guarda siempre, incluso si no se entiende.**
Este es el arreglo estructural del bug de `status = 255`. Hoy un código de estado inválido produce `check_type = unknown`, el registro queda `pending` para siempre y **el marcaje de ese día del empleado se pierde en silencio**. En el diseño objetivo el byte se guarda igual; la interpretación es un paso posterior, versionado y **re-ejecutable**. Se arregla la regla, se reinterpreta, se recupera el dato. Sin backfill manual, sin aprobación de por medio, sin tocar nómina a mano.

**(b) La interpretación es un paso aparte, no parte de la ingesta.**
`interpretation_version` permite responder la pregunta que hoy no se puede responder: *"¿qué habría pasado con estos eventos si la regla hubiera sido otra?"*. Es también el mecanismo de rollback: si una regla nueva resulta mala, se reinterpreta con la anterior.

**(c) Autenticar el canal, ya que el protocolo no se puede autenticar.**
El reloj no puede firmar nada. Pero el canal sí se puede cerrar, en capas:

- **Registro de dispositivo con vínculo a red:** en el onboarding se captura la IP/CIDR de origen y se **alerta** si un `SN` conocido aparece desde otra red. Barato, sin tocar el equipo, y detecta el ataque real (alguien con un número de serie desde fuera).
- **Allowlist por cliente en el borde** (nginx o Cloudflare), donde la red del cliente lo permita.
- **Rechazo de `SN` desconocido antes de arrancar PHP** — un `map` de nginx o un `SET` en Redis. Hoy el 3,8% del tráfico son escaneos externos que sí arrancan el framework.
- **Límite de tasa por número de serie** y límite de tamaño de petición.
- **Túnel o VPN** donde el cliente lo permita — es lo correcto, y no siempre es negociable.

**Y una regla de plataforma, para lo que sí controlamos:** toda fuente cuyo protocolo diseñemos nosotros **se autentica desde el día uno**. El webhook de Provision lleva HMAC con marca de tiempo y ventana anti-replay. ZKTeco es una excepción documentada con controles compensatorios, no un precedente.

**(d) Sacar el polling del camino caliente.**
33.570 peticiones al día —el 90,7% del tráfico— arrancan un ciclo completo de Laravel para devolver, casi siempre, **2 bytes vacíos**. Es el consumidor dominante de CPU del servidor y no produce ningún valor.

Ruta de evolución, en orden de esfuerzo:

1. **Hoy:** ruta dedicada con middleware mínimo; la existencia de un comando pendiente se consulta en **Redis**, no en MySQL. Sin comando → responder y salir. Coste ~1 ms en vez de un bootstrap completo.
2. **Después:** `nginx` + Lua leyendo la misma clave de Redis, y solo cae a PHP cuando **sí** hay un comando. Elimina el 90% del tráfico del intérprete.
3. **Si el volumen lo justifica:** un proceso Go de 200 líneas como sidecar del borde.

Con 28 equipos no es urgente. Con 150 —el objetivo de escala— es la primera pared que se golpea. Diseñarlo así desde el principio cuesta lo mismo.

**(e) La zona horaria es un dato, no una constante.**
Hoy `buildInitResponse` fija `TimeZone=-6` para todos los dispositivos de todos los clientes. Funciona porque todos los clientes actuales están en el mismo huso. En el diseño objetivo la zona vive en el `Client` y, si hace falta, en la sede (`FactorialLocation`); el evento se guarda en UTC con su zona declarada. El riesgo R6 del expediente MLA es exactamente este problema por el otro extremo: el equipo de Provision está en Israel/Asia y esos sistemas suelen entregar UTC.

### 3.3 Capa 1 — Identidad

**Responsabilidad:** convertir "el PIN 47 del reloj UEED244300146" en "la persona Ana Martínez, del cliente 3".

Hoy la cadena es `employee_code` → `BiometricUserSync.external_employee_code` → `factorial_employee_id`: un vínculo 1:1:1 que **no puede expresar que la misma persona sea vista por dos fuentes distintas**. Ese es precisamente el problema abierto del expediente MLA (§4.4: cámara y reloj conviviendo) y la razón de que hoy no exista regla de precedencia.

```
persons                        -- la persona canónica, por cliente
  id, client_id, display_name, status, created_at

person_identifiers             -- N identificadores por persona
  id, person_id, kind, value
  -- kind: zk_pin | factorial_id | provision_id | payroll_code | email
  UNIQUE (client_id, kind, value)
```

**Qué desbloquea, concretamente:**

- **Cámara y reloj conviviendo:** ambos resuelven a la **misma** `person`. La regla de precedencia ("la primera entrada del día gana, venga de donde venga") se expresa a nivel de persona-día, que es donde tiene sentido. Hoy la defensa antiduplicado es `fuente + empleado + hora`, así que dos fuentes distintas **pasan el filtro** y el duplicado llega a Factorial.
- **Empleados locales** (los que no van a Factorial) dejan de ser un caso especial: son personas sin identificador `factorial_id`.
- **Migración de un empleado entre dispositivos o entre sedes** deja de perder su historia.
- **El mapeo MLA sale gratis** si Provision carga como ID único el mismo código que la persona ya tiene: es un `person_identifier` más.

**Y el triaje deja de ser un vertedero.** Hoy hay **1.278 registros `pending`, el más antiguo del 2 de junio** — tres meses. Eso no es una cola, es un cementerio. En el diseño objetivo:

- Un evento sin identidad entra a triaje **con SLA y con alerta**, no a un estado terminal silencioso.
- Reglas de descarte automático: pasados N días sin mapeo posible, se archiva con motivo explícito.
- Antes de programar `attendance:resolve-pending` cada 30 minutos hay que **clasificar esos 1.278**: si el empleado nunca va a existir (baja, PIN de prueba, equipo mal mapeado), reintentarlo en bucle solo gasta ciclos.

### 3.4 Capa 2 — La Jornada. Aquí está el corazón del rediseño

**Responsabilidad:** dada una persona y un día laboral, decir cuál fue su jornada real. Esta capa **es la verdad del sistema**.

```
work_days                      -- el agregado. Uno por persona y día laboral
  id, client_id, person_id, business_date
  status                       -- open | closed | needs_review | disputed
  computed_intervals           -- JSON: [{kind, start, end, derived_from[], rule}]
  derivation_version
  derived_at
  UNIQUE (person_id, business_date)

work_day_events                -- qué eventos alimentaron esta jornada
  work_day_id, ingest_event_id, role

work_day_overrides             -- correcciones humanas. Ganan siempre
  work_day_id, field, value, actor_id, reason, created_at
```

**La derivación es una función pura:**

```
derive(eventos[], reglas_del_cliente, correcciones[]) → intervalos[]
```

Pura significa: sin base de datos dentro, sin llamadas de red, sin reloj del sistema. Que se pueda ejecutar mil veces con la misma entrada y dé la misma salida. Eso la vuelve **testeable con propiedades**, **re-ejecutable** sobre datos históricos y **explicable** frente a una disputa de nómina.

**Las cinco reglas que hoy están dispersas y aquí quedan juntas:**

1. **El día laboral es una regla del cliente, no la fecha del calendario.** Fija el corte (p. ej. 04:00) y los turnos nocturnos se resuelven **por definición**, en vez del parche actual de "mirar también el día anterior si es antes de las 6am".
2. **El tipo de marcaje se deriva, no se congela en la ingesta.** En orden: (i) el estado del dispositivo si está mapeado y es coherente; (ii) **alternancia** dentro de la jornada — si el anterior fue entrada, este es salida; (iii) la política del cliente. La regla (ii) es la que hace que un `status = 255` deje de perder el día.
3. **`checkin_only` deja de ser un modo y pasa a ser una regla:** "la primera detección de la persona en el día es su entrada; el resto se suprime". Esto implementa el *retén de primera detección* que pide el expediente MLA (§4.2) sin escribir un camino de código nuevo.
4. **La precedencia entre fuentes es una regla del agregado**, no una comparación de filas. Resuelve el riesgo R4 del expediente MLA, que hoy no tiene solución.
5. **El cierre de turno olvidado es una regla que completa el estado deseado**, no un mecanismo aparte. "Si la jornada no tiene salida y la política del cliente dice cerrarla, el intervalo termina en `entrada + minutos planificados`." El reconciliador de la capa 3 la aplica sola. Esto **elimina** `CloseForgottenShifts`, su job, su scheduler y —lo más importante— **el dispositivo virtual sintético**, que existe únicamente porque `attendance_logs.biometric_source_id` no admite nulos. Ese hack ya causó un bug real: 13 consultas sin filtrar en `employee-sync-manager.blade.php`, y un conteo inflado que hacía que empleados mapeados a *todos* los dispositivos se mostraran como "Varios".

**Cada derivación deja rastro.** `derived_from[]` y `rule` en cada intervalo responden la pregunta que un cliente hace tarde o temprano: *"¿por qué el sistema dice que Ana salió a las 18:00?"*. Hoy esa respuesta hay que reconstruirla leyendo logs. En un sistema de nómina, la trazabilidad no es una comodidad: es el producto.

### 3.5 Capa 3 — Proyección y reconciliación hacia Factorial

**Responsabilidad:** que Factorial acabe reflejando la jornada. No "ejecutar comandos": **converger**.

```
factorial_shift_links
  work_day_id, factorial_connection_id
  factorial_shift_id           -- SABEMOS cuál es. No hay que buscarlo
  desired_state_hash           -- qué queríamos
  pushed_state_hash            -- qué logramos empujar
  last_pushed_at, state        -- in_sync | drifted | pending | conflict
```

**El bucle, completo:**

```
1. Una jornada cambia (evento nuevo, regla nueva, corrección humana) → se marca "sucia"
2. Calcular el estado deseado a partir de los intervalos
3. ¿desired_hash == pushed_hash?  → nada que hacer. Salir.
4. ¿Hay vínculo?  Sí → leer ese turno concreto por id (una llamada, sin ventana de fechas)
                  No → buscar una vez y vincular, o crear
5. Calcular el diff mínimo y emitir solo las llamadas necesarias
6. Guardar el vínculo y el hash empujado
7. ¿Falló?  → transitorio: reintentar con backoff (SIEMPRE seguro: es convergencia)
              negocio:     marcar en conflicto y ESCALAR a un humano
```

**Lo que este bucle elimina de golpe:**

| Se elimina | Por qué deja de hacer falta |
|---|---|
| La cascada de 4 fallbacks | El estado deseado se calcula siempre, no solo cuando algo falla |
| La marca `Editado por biométrico SFT` en `observations` | El estado vive en `pushed_state_hash`, local. Ya no se usa un campo remoto como almacén |
| El límite de ventana de `findOpenShift` | El turno se lee **por id**, no se busca por fecha |
| `CloseForgottenShifts` como mecanismo | Pasa a ser una regla de la capa 2 |
| `tries = 1` | Converger es idempotente por construcción. Reintentar es seguro |
| El dispositivo virtual `AUTOCLOSE-{client_id}` | Ya no hace falta inventar un dispositivo físico para un cierre del sistema |
| El retardo global de 2 s y la serialización | El orden es una propiedad del cálculo, no del encolado. Solo se serializa **por persona** |

**El cliente HTTP, endurecido.** Esto sí es refinamiento de lo existente, y coincide con la Fase 3 del diagnóstico:

- `connectTimeout(5)` — hoy no existe: una conexión que no abre cuelga el worker 30 s.
- `timeout(20)`, no 30.
- **Excepciones tipadas**, que es lo que hoy falta para poder reintentar con criterio:
  - `FactorialTransientException` (red, 5xx, 429) → **reintentar**
  - `FactorialBusinessException` (turno solapado, política de empleado) → **no reintentar, escalar**
  - `FactorialAuthException` (token muerto) → **pausar la conexión y alertar**
  Hoy los 7 `HTTP request failed` de la tabla de fallos se pierden igual que un conflicto de negocio legítimo, porque **no hay forma de distinguirlos**.
- **Manejo explícito de 429** con `Retry-After`. Hoy no se contempla en absoluto.
- **Limitador de salida** hacia Factorial. 60/min es un punto de partida conservador; **hay que confirmar el límite real con su soporte**, por el mismo canal por el que se llevó el asunto de `toggle_clock`, y ajustar con dato, no con suposición.
- **Circuit breaker por conexión:** ante N fallos de red consecutivos, abrir 5 minutos y devolver los jobs a la cola sin gastar intentos. Una caída de 10 minutos de Factorial deja de convertirse en cientos de `failed_jobs`.
- **Salud del token OAuth:** el refresco con lock distribuido ya está bien resuelto. Falta lo otro: si el `refresh_token` muere, hoy el síntoma es una acumulación silenciosa de fallos. Debe ser una **alerta inmediata** con un enlace para reconectar.

**Y el hueco que sigue abierto:** `edit_timesheet_requests` para los turnos olvidados de clientes `checkin_only = false`. La decisión de no adivinar el enum contra la API de un cliente real fue correcta. En el diseño objetivo, mientras eso no se resuelva, un turno cerrado automáticamente en un cliente que **sí** debería marcar salida es un `work_day` en estado `needs_review` que **dispara una alerta**, no una nota de color que alguien tiene que entrar a mirar.

### 3.6 Capa 4 — Plataforma

| Pieza | Hoy | Objetivo | Por qué |
|---|---|---|---|
| **Cola** | `database`, 1 worker, todo mezclado | **Redis** + colas por criticidad: `ingest` / `attendance` / `bulk` / `maintenance` | Un lote masivo de un cliente no puede bloquear el fichaje en vivo de otro. Hoy sí lo hace |
| **Workers** | 1 proceso, `--sleep=3` | 2 en `attendance`, 1 en `bulk`, con `stopwaitsecs=120` | `stopwaitsecs=3600` deja código viejo corriendo **una hora** tras un despliegue |
| **Caché y locks** | `database` | **Redis** | Cada lectura de caché es hoy una consulta a MySQL. Redis ya está en el `.env` pero **no hay proceso escuchando** |
| **Scheduler** | Un `crontab` fuera del repositorio | Definiciones en código + la entrada de cron **provisionada y verificada**, con alerta si deja de latir | Hoy no sobrevive a un servidor nuevo, y nadie se entera si se para |
| **Respaldos** | **Ninguno** | `mysqldump` cifrado a S3, retención escalonada, y **restauración probada trimestralmente** | Es el hueco operativo más grave del sistema. Un respaldo sin restauración probada no es un respaldo |
| **Logs** | `INFO`, 95 MB/día, incidentes enterrados | `WARNING` estructurado en JSON, rotación 14 días, polling a `DEBUG` | Los 7.021 errores de UTF-8 quedaron sepultados bajo 88 MB de ruido de polling |
| **Métricas** | Ninguna | Marcajes ingeridos, latencia de sync, tasa de fallo **por causa**, profundidad de cola, último latido por dispositivo | Sin esto, "funciona" significa "nadie se ha quejado todavía" |
| **Alertas** | Ninguna | Tasa de fallo > umbral · dispositivo mudo > N min · conexión OAuth caída · circuito abierto · cola creciendo · jornadas en revisión | 25 respuestas HTTP 500 pasaron inadvertidas |
| **Retención** | Ninguna | `device_inventory_users`: guardar **diferencias**, no fotos completas | 271.561 filas, 94,8 MB, **+30.000 filas al día**. Con 4,2 GB libres, ~14 meses y contando |
| **Entornos** | Producción, y XAMPP en Windows | **Staging** con simulador de dispositivo y doble de Factorial | Hoy no hay dónde probar un cambio que toca nómina |

---

## 4. Modelo de datos objetivo, completo

```
IDENTIDAD Y TENENCIA
  clients                  id, name, timezone, status, settings
  users                    id, client_id?, role                    -- admin | client
  persons                  id, client_id, display_name, status
  person_identifiers       person_id, kind, value                  UNIQUE(client_id, kind, value)

FUENTES
  sources                  id, client_id, kind, external_ref, status, config
                           -- kind: zkteco | provision | import | manual | system
                           -- 'system' hace innecesario el dispositivo virtual
  source_devices           source_id, serial_number, firmware, protocol_profile,
                           expected_cidr, last_seen_at, location_id
  device_commands          source_device_id, payload, state, seq, acked_at

INGESTA  (append-only)
  ingest_events            id, client_id, source_id, source_kind, external_key,
                           occurred_at, occurred_at_tz, raw_payload, received_at,
                           interpretation_version, interpreted_at
                           UNIQUE(source_id, external_key)
  ingest_triage            ingest_event_id, reason, state, sla_due_at, resolved_by

DOMINIO
  work_days                id, client_id, person_id, business_date, status,
                           computed_intervals, derivation_version, derived_at
                           UNIQUE(person_id, business_date)
  work_day_events          work_day_id, ingest_event_id, role
  work_day_overrides       work_day_id, field, value, actor_id, reason

PROYECCIÓN
  factorial_connections    client_id, tokens(cifrados), company_id, health, last_ok_at
  factorial_employees      connection_id, factorial_id, ...            -- catálogo
  factorial_locations      connection_id, factorial_id, ...            -- catálogo
  factorial_shift_links    work_day_id, connection_id, factorial_shift_id,
                           desired_state_hash, pushed_state_hash, state, last_pushed_at

CONFIGURACIÓN
  client_attendance_rules  client_id, business_day_cutoff, status_map,
                           checkin_only, auto_close_policy, source_precedence
```

**Cinco decisiones de esquema que vale la pena defender:**

1. **`ingest_events` separado de `work_days`.** Hoy `attendance_logs` mezcla el hecho crudo con su interpretación y con su estado de sincronización — tres ciclos de vida distintos en una tabla. Separarlos es lo que permite reinterpretar sin perder el original.
2. **`source_kind = 'system'` como ciudadano de primera.** Un cierre automático es un evento del sistema, no de un dispositivo. Elimina de raíz el hack `AUTOCLOSE-{client_id}` y las 13 consultas que hay que acordarse de filtrar.
3. **`person_identifiers` en vez de una columna por sistema.** Añadir Provision es insertar filas, no migrar esquema.
4. **`UNIQUE (person_id, business_date)` en `work_days`.** Convierte "una persona tiene una jornada al día" en un invariante de base de datos, no en una convención que hay que recordar.
5. **Aislamiento multi-tenant estructural.** Hoy el aislamiento depende de que cada consulta nueva recuerde su `where('client_id', ...)` — el patrón de riesgo más común en aplicaciones multi-tenant, y el equipo ya lo sabe (existe `MultiTenantAuthorizationTest`). En el diseño objetivo se aplica un **global scope** de tenant en un modelo base, de modo que **olvidarlo sea imposible en vez de improbable**, con una vía de escape explícita y auditada para los procesos de administración.

---

## 5. El borde ZKTeco, en detalle

Lo que **no** cambia, porque no se puede: HTTP plano, sin autenticación, sin CSRF, iniciado por el equipo, ACK rápido obligatorio.

Lo que cambia:

| Aspecto | Hoy | Objetivo |
|---|---|---|
| **Ruta de polling** | Bootstrap completo de Laravel, 33.570 veces al día, para devolver 2 bytes | Consulta a Redis; nginx+Lua cuando el volumen lo pida |
| **`SN` desconocido** | Arranca PHP y luego rechaza | Se rechaza en el borde, antes del intérprete |
| **Origen de red** | Sin verificar | Vinculado en el onboarding, alerta al cambiar |
| **Límite de tasa** | Ninguno | Por `SN`, y límite de tamaño de petición |
| **Zona horaria** | `-6` fijo para todos | Por cliente y sede |
| **ATTLOG vs. rtlog** | **Dos rutas de código paralelas** que ya divergieron: `rtlog` no filtra por `status='active'` en `BiometricUserSync` | **Un solo intérprete**. La diferencia de transporte no justifica dos implementaciones de la misma regla |
| **Import `.dat`** | Orden de columnas **distinto** al del push en vivo, advertido solo en un comentario | Un adaptador con contrato declarado por **nombre** de columna, nunca por posición |
| **Estado inválido (`255`)** | Se pierde el marcaje del día | El evento crudo se guarda; la alternancia lo deriva; reinterpretable |
| **UTF-8 roto en OPERLOG** | Un nombre con `Ñ` truncada devolvía 500 y **el equipo dejaba de subir asistencia pese a verse "En línea"** | Sanear en el borde; un payload que no se puede decodificar se guarda como bytes y se marca, **nunca tumba la ingesta** |

Ese último merece énfasis: el incidente del 31 de agosto es el caso más peligroso de todos porque **el sistema se veía sano**. El dispositivo reportaba "En línea", el panel no mostraba nada raro, y la asistencia simplemente no llegaba. Un borde que nunca falla por contenido, más una alerta de "este equipo lleva N minutos sin entregar eventos", lo convierte en un aviso de 5 minutos en vez de un día perdido.

**Lo que sí está bien y hay que conservar:** el `DeviceProtocolResolver` guiado por `config/biometric-protocols.php`, con la regla de no meter condiciones de modelo en los controladores. Es exactamente el patrón correcto para un ecosistema de dispositivos heterogéneo, y es lo que hará barato absorber la matriz de modelos de cámara que pide el entregable E5 del expediente MLA.

---

## 6. El canal a Factorial, en detalle

**Lo que se conserva:** OAuth por cliente con credenciales propias, tokens cifrados, `state` aleatorio ligado al usuario, refresco con lock distribuido, versión de API fijada por fecha, construcción manual del query en formato Rails (documentada — cualquier refactor tiene que preservarla).

**Lo que se añade:**

1. **Un solo `FactorialClient`**, con las tres excepciones tipadas de §3.5. Hoy hay **tres implementaciones** de "traer empleados de Factorial" (`SyncFactorialEmployees`, `SyncFactorialConnection`, y la lógica repetida en el servicio): un arreglo de paginación aplicado en un sitio deja los otros dos desactualizados.
2. **El reconciliador de §3.5** como único escritor de turnos. Un solo camino de escritura, no cinco.
3. **Salud de la conexión como dato de primera clase:** `health`, `last_ok_at`, `consecutive_failures`. Alimenta el circuit breaker, el panel y las alertas.
4. **Paginación con criterio uniforme.** `factorial:sync-locations` hoy **no pagina** — asume que todas las sedes caben en una respuesta.
5. **Decidir explícitamente sobre el código muerto.** `getBreakConfigurations`, `createShift`, `deleteShift` y `toggleClock` no tienen ningún llamador. En un servicio de integración externa, el código muerto es indistinguible de "esto se usa en un flujo que no vi". O se borra, o se documenta por qué se queda.
6. **Contrato de pruebas registrado.** Los dos formatos de error observados en producción (`errors.exception` y `errors.errors`) y los rechazos de política (`Forbidden by Attendance::EmployeePolicy::ClockInOut`) tienen que ser fixtures, no descubrimientos.

**Y `toggle_clock` sigue vetado.** Si alguna vez se reabre, el orden es: entender primero *por qué* corrompió turnos en producción, reproducirlo en staging contra el doble de Factorial, y solo entonces discutirlo. La recomendación oficial de su soporte no basta como evidencia: ya se siguió dos veces y salió mal las dos.

---

## 7. Servidor e infraestructura

**El servidor no está saturado.** Load 0,64 sobre 2 vCPU, 1,5 GB de RAM libres, buffer pool de MySQL con 99,977% de aciertos. El problema nunca fue la capacidad.

| Elemento | Hoy | Objetivo | Nota |
|---|---|---|---|
| Instancia | t3.micro, 3,7 GB | t3.micro ahora; **t3.small al añadir Redis + 3 workers** | El cálculo de memoria queda al límite: ~1,75 GB de pico sobre 3,7 GB |
| Disco | 13 GB, 67% usado | Retención + rotación | El único vector real de agotamiento |
| PHP-FPM | `pm = dynamic` | `pm = static` | El tráfico es **constante 24/7**, no a ráfagas. `dynamic` gasta ciclos creando y destruyendo hijos para nada |
| Redis | En el `.env`, **sin proceso** | Cola, caché, locks, y la clave de comandos del borde | La pieza que desbloquea la mitad del plan |
| TLS / borde | TLS obsoleto declarado, origen accesible en HTTP plano, `trustProxies('*')` | TLS moderno, origen solo accesible vía Cloudflare, IPs reales de Cloudflare declaradas | `trustProxies('*')` con el origen expuesto permite falsificar la IP de cliente |
| Respaldos | **Ninguno** | Diario cifrado a S3 + restauración probada | Prioridad máxima |
| Despliegue | Manual + `config:cache` | Etiquetado, con gate de migraciones, reinicio de workers, health check y rollback | |
| Secretos | `.env` con permisos laxos y un `.env.bak` en claro al lado | `chmod 640`, sin copias en el árbol de la aplicación | |
| `APP_KEY` | Sin respaldo documentado | Respaldada aparte del volcado de base de datos | Si se pierde, **todas** las conexiones OAuth quedan irrecuperables |

---

## 8. Seguridad y cumplimiento

1. **Autenticación de fuentes** — §3.2(c). El vector real: cualquiera que conozca un número de serie puede inyectar asistencia que llega a nómina.
2. **Aislamiento multi-tenant estructural** — global scope, no disciplina. §4, decisión 5.
3. **Datos personales.** Esto maneja datos biométricos y de asistencia de personas identificadas en México: aplica la LFPDPPP. Hay que definir por escrito quién es responsable y quién encargado del tratamiento (Sintelc procesa por cuenta del cliente), y con eso caen: aviso de privacidad, política de retención, derechos ARCO, y control de acceso de los usuarios de Sintelc a los datos de un cliente. **No está resuelto hoy y es un requerimiento legal, no una buena práctica.**
4. **Plantillas biométricas (`BIODATA`).** Son el dato más sensible del sistema. Hay que decidir explícitamente si se almacenan, cuánto tiempo, y cifradas con qué llave. Hoy se guardan para poder clonar un equipo a otro.
5. **Rate limiting y límite de tamaño** en todos los endpoints públicos. El 3,8% del tráfico son escaneos externos.
6. **`fail2ban`** para los escáneres.
7. **Registro de auditoría** de acciones administrativas que tocan datos de nómina: quién corrigió qué jornada, cuándo y por qué. `work_day_overrides` ya lo lleva por diseño.
8. **El JWT decodificado sin verificar firma** en el callback de OAuth es **correcto** en este contexto —viene de un intercambio ya autenticado y solo se usa para leer `company_id`— pero hay que documentarlo en el propio código, para que nadie lo "arregle" creyendo que es una vulnerabilidad.

---

## 9. Estrategia de pruebas

La cobertura actual es mejor de lo habitual en un proyecto de este tamaño: hay pruebas de los mecanismos críticos recientes (cierre de turnos, seguridad OAuth, multi-tenencia). Lo que falta es lo que hace posible **cambiar** con confianza.

| Nivel | Qué prueba | Por qué importa aquí |
|---|---|---|
| **Propiedades sobre la derivación** | Cualquier permutación de los mismos eventos produce la misma jornada | Es la garantía formal que sustituye al retardo de 2 s y al worker único. **La prueba que permite borrar el cuello de botella** |
| **Simulador de dispositivo** | Reproduce capturas reales de ATTLOG/USERINFO/OPERLOG | Con fixtures de regresión del `status=255` y de la `Ñ` truncada. Los incidentes reales se convierten en pruebas |
| **Doble de Factorial** | Respuestas grabadas, incluidos los dos formatos de error y los rechazos de política | Hoy los conflictos de negocio se descubren en producción |
| **Reconciliación** | Converger dos veces no produce cambios la segunda vez | La definición operativa de idempotencia |
| **Multi-tenencia** | Ya existe; extenderla a cada componente Livewire nuevo | Un test no cubre la consulta que alguien escriba mañana; el global scope sí |
| **Corrección de cierre anticipado** | **No tiene prueba** — se añadió el mismo día de la auditoría | Es un camino de escritura hacia datos de nómina sin red |

**Y una regla de proceso:** todo incidente de producción entra al repositorio como prueba de regresión antes de darse por cerrado. El `255`, la `Ñ`, el turno abierto de 24 h. Es lo que evita que un sistema con esta cantidad de casos borde retroceda.

---

## 10. Observabilidad y operación

Lo que hay que poder responder **sin abrir una consola**:

- ¿Llegó hoy la asistencia de todos los clientes?
- ¿Qué dispositivo lleva más de N minutos sin entregar eventos? *(el incidente de la `Ñ` es exactamente esto)*
- ¿Cuántas jornadas están en revisión, y desde cuándo?
- ¿Qué conexión de Factorial tiene el token muerto?
- ¿Cuál es la latencia entre el marcaje y su llegada a Factorial, por cliente?
- ¿Está creciendo alguna cola?

**Alertas, con destinatario y umbral:**

| Alerta | Umbral de partida |
|---|---|
| Tasa de fallo terminal | > 2% en 1 h |
| Dispositivo mudo | Sin eventos en 2× su intervalo habitual |
| Conexión OAuth caída | Inmediata |
| Circuito abierto hacia Factorial | Inmediata |
| Profundidad de cola | > 500 o job más antiguo > 10 min |
| Jornadas en revisión | > 10 sin atender en 24 h |
| Respaldo no completado | Inmediata |

**Y el runbook mínimo**, que hoy no existe: qué hacer cuando un cliente dice "no me llegó la asistencia de ayer". Hoy esa respuesta vive en la cabeza de quien construyó el sistema, y el sistema está cambiando de manos.

---

## 11. Lo que el diseño actual hizo bien

Explícito, para que no se pierda en la migración:

| Decisión | Por qué está bien |
|---|---|
| Clave única `(source, pin, timestamp)` | La defensa correcta contra el reenvío de buffer del propio protocolo |
| `tries = 1` con verificación de idempotencia previa | Es la decisión **correcta** dentro del modelo actual. Cambia solo porque cambia el modelo |
| Lock distribuido en el refresco de token | Evita el refresco doble que invalidaría el primer token. Detalle fino, bien resuelto |
| Tokens y secretos OAuth cifrados | Correcto |
| `state` de OAuth ligado al usuario, con Hashids en la URL | Mitiga CSRF del propio flujo. Cuidado real |
| `DeviceProtocolResolver` por configuración | El patrón correcto para dispositivos heterogéneos. Se conserva tal cual |
| Detección de descanso real por el campo `source` | Verificado contra la API real comparando el mismo empleado en día de descanso y día laboral. **Trabajo de investigación de calidad** |
| Rechazo documentado de `toggle_clock` | Registrar por qué **no** se hizo algo vale tanto como registrar lo que se hizo |
| Reintentos con backoff en `CloseForgottenShiftJob` | Distingue correctamente dónde reintentar sí conviene |
| Query en formato Rails, con el porqué en un comentario | Detalle no obvio, documentado donde se necesita |
| Documentación interna honesta | Registra los huecos en vez de esconderlos. Es lo que hace posible tomar el proyecto |

---

## 12. Plan de migración — esto es lo que se ejecuta

Estrangulamiento progresivo. Ninguna fase deja el sistema peor que la anterior, y cada una tiene criterio de salida y vuelta atrás.

### Fase 0 — Ver y no perder *(semana 1, riesgo nulo)*

No cambia comportamiento. Compra visibilidad y una red de seguridad.

- **Respaldo automatizado con restauración probada.** Primero, antes que nada.
- `LOG_LEVEL=warning`, polling a `DEBUG`, rotación a 14 días.
- Métricas y las alertas mínimas de §10.
- `chmod 640` en `.env`, eliminar `.env.bak`, respaldar `APP_KEY` aparte.
- `stopwaitsecs` de 3600 → 120.
- **Staging** con simulador de dispositivo.
- Congelar features nuevas mientras dura.

**Salida:** existe un respaldo restaurado con éxito, y una alerta que suena si la asistencia deja de llegar.

### Fase 1 — El borde *(semanas 2-3, riesgo bajo)*

- `ingest_events` **en escritura paralela** al flujo actual. Sin cambio de comportamiento: solo se empieza a guardar el crudo.
- Redis en producción; cola y caché migradas; colas separadas y 2 workers en `attendance`.
- Retardo **por persona** en vez de global. *(Efecto medido en el diagnóstico: un lote de 400 registros de 50 empleados pasa de 13,3 min a ~16 s de retardo programado.)*
- Endurecer el cliente HTTP: `connectTimeout`, 429, excepciones tipadas.
- Rechazo de `SN` desconocido en el borde, límite de tasa, límite de tamaño.
- Saneado de UTF-8 en la ingesta.

**Salida:** existe un registro crudo de todo lo que entra, y un lote masivo ya no bloquea a los demás clientes.

### Fase 2 — Identidad *(semana 4, riesgo bajo)*

- `persons` + `person_identifiers`, poblados desde `BiometricUserSync` y `FactorialEmployee`.
- Las tablas viejas se mantienen como vistas o adaptadores: nada de la UI se toca todavía.
- **Clasificar los 1.278 `pending`**: cuáles se pueden resolver, cuáles hay que archivar y por qué.

**Salida:** una persona tiene un identificador por fuente, y el vertedero de `pending` está clasificado.

### Fase 3 — La jornada, en sombra *(semanas 5-8, riesgo nulo — no escribe nada)*

**Esta es la fase que hace segura toda la migración.**

- Implementar la derivación pura y calcular `work_days` para **todo el histórico**.
- Para cada jornada, calcular el estado deseado y **compararlo con lo que el sistema realmente hizo en Factorial**.
- Publicar un informe de divergencias y **explicar cada una**.
- Correr en sombra 2–4 semanas.

**Salida:** cada divergencia entre el modelo nuevo y la realidad está explicada. Ninguna sorpresa llega a nómina. **Si esta fase no converge, no se avanza** — y eso, en sí mismo, es información valiosa.

### Fase 4 — El reconciliador *(semanas 9-12, riesgo controlado)*

- Activar la escritura por convergencia **para un cliente piloto**, detrás de una bandera.
- Elegir el piloto por riesgo bajo, no por dolor alto.
- Vigilar una semana. Extender cliente por cliente.
- Conforme cada comportamiento queda cubierto por una regla, retirar su mecanismo viejo: primero la cascada de fallbacks, después `CloseForgottenShifts`, al final el dispositivo virtual.

**Salida:** todos los clientes convergiendo, tasa de fallo terminal < 1%, cero mecanismos duplicados.

### Fase 5 — MLA / Provision *(en paralelo desde la Fase 2)*

El expediente MLA se resuelve **casi entero con lo ya construido**:

| Pieza | De dónde sale |
|---|---|
| Modo solo-entradas | Regla de la capa 2 — ya existe conceptualmente |
| Retén de primera detección del día | Regla de la capa 2, no código nuevo |
| Precedencia entre cámara y reloj | Cae sola con `persons` (Fase 2) — hoy **no existe** |
| Mapeo persona ↔ empleado | `person_identifiers` |
| Antiduplicados, orden, envío, cierre automático | Se reutiliza completo |
| **Lector de la fuente Provision** | **Lo único genuinamente nuevo**: un adaptador de capa 0 |
| **Autenticación del webhook** | **Nuevo, y no negociable**: HMAC desde el día uno |

Sigue bloqueado por los entregables E1, E2 y E3 de Provision. **Y el backfill pendiente de Outlandish (40 registros del 15-17 de agosto) es bloqueante**: es el mismo cliente, y no conviene sumar una fuente nueva sobre datos de nómina que aún están sin cuadrar.

### Orden de las cosas, en una línea

```
Fase 0  Respaldos + visibilidad          ██  semana 1        riesgo nulo
Fase 1  Borde + Redis + colas            ████  sem 2-3       riesgo bajo
Fase 2  Identidad                        ██  semana 4        riesgo bajo
Fase 3  Jornada EN SOMBRA                ████████  sem 5-8   riesgo nulo (no escribe)
Fase 4  Reconciliador, cliente a cliente ████████  sem 9-12  riesgo controlado
Fase 5  MLA / Provision                  ────────────►       en paralelo desde F2
```

---

## 13. Trade-offs, explícitos

Ningún diseño es gratis. Estos son los costes reales de lo que propongo:

| Decisión | Qué cuesta | Por qué aun así conviene |
|---|---|---|
| Guardar el evento crudo aparte de su interpretación | Una tabla más, más almacenamiento, un paso más | El dominio es dato disputado de nómina y la fuente es poco fiable. Poder reinterpretar vale más que el disco |
| Derivación como función pura | Más código por adelantado; hay que resistir la tentación de meterle una consulta dentro | Es lo que hace testeable, re-ejecutable y **explicable** el cálculo. Sin pureza no hay prueba de propiedades, y sin ella no se puede borrar el cuello de botella |
| Reconciliar en vez de comandar | Más lecturas a Factorial → presión sobre su límite de peticiones | Se mitiga con el vínculo guardado y el hash: solo se lee cuando el hash difiere |
| Redis | ~150 MB de RAM; probablemente **t3.small** | Desbloquea las colas separadas, los locks y el borde rápido. Es la pieza con mejor relación desbloqueo/coste |
| Fase en sombra de 4 semanas | Un mes sin beneficio visible | Es la única forma honesta de cambiar el motor de un sistema de nómina en vivo. Saltársela es el riesgo grande del plan |
| Global scope de tenant | Hay que gestionar la vía de escape para procesos de administración | Convierte el fallo multi-tenant más común de improbable en imposible |
| **Seguir en Laravel + Livewire, monolito modular** | Ninguno relevante | El stack no es el problema. Reescribir sería sustituir un modelo de dominio equivocado por un modelo equivocado en otro lenguaje |
| **No microservicios** | — | Con 1-2 personas, los límites entre módulos se sostienen con disciplina y pruebas; los límites de red solo añaden fallos parciales |

---

## 14. Qué revisaría al crecer

| Disparador | Qué cambia |
|---|---|
| > 50 clientes / > 150 dispositivos | El borde de polling sale de PHP (nginx+Lua o un sidecar en Go). Base de datos a RDS |
| > 1 M eventos/mes | Particionar `ingest_events` y `work_days` por mes; archivar a S3 |
| Un cliente exige aislamiento o residencia de datos | Esquema por tenant. El global scope ya deja el camino preparado |
| Aparece un segundo destino además de Factorial | La capa 3 se vuelve una interfaz con adaptadores, igual que ya lo es la capa 0 |
| El equipo pasa de 2 a 5 personas | Los módulos de `app/Domain/` pueden hacerse paquetes con fronteras verificadas en CI |
| Factorial publica webhooks de turnos | La reconciliación pasa de programada a dirigida por eventos. El bucle no cambia |

---

## 15. Decisiones que no son mías

Estas bloquean o cambian el plan, y las tiene que tomar alguien más:

1. **¿Se aprueba el backfill de los 40 registros de Outlandish?** Bloquea la Fase 5. Toca nómina.
2. **¿Las cámaras de MLA reemplazan al reloj o conviven?** Cambia el alcance de la Fase 5. Es respuesta de MLA, no de Provision.
3. **¿MLA, MyNekMan y Outlandish son la misma entidad?** De la respuesta depende buena parte del expediente.
4. **¿Cuál es el límite real de peticiones de la API de Factorial?** Hay que preguntarlo a su soporte, no estimarlo.
5. **¿Qué hace `edit_timesheet_requests`?** Hace falta el esquema de campo de su soporte para cerrar el hueco de visibilidad.
6. **¿Hay presupuesto para t3.small?** Condiciona la Fase 1.
7. **¿Quién responde a las alertas, y en qué horario?** Una alerta sin dueño es un log más caro.
8. **¿Quién es responsable y quién encargado del tratamiento de datos personales?** Es una definición legal con consecuencias de diseño.

---

## 16. Cierre

Si tuviera que resumir la estrategia en tres frases:

1. **La verdad de la asistencia tiene que vivir aquí, no en Factorial.** Todo lo demás —los reintentos seguros, la desaparición de la cascada de fallbacks, la recuperación del `status 255`, el fin del cuello de botella— se sigue de esa sola decisión.
2. **La ingesta, la identidad, el dominio y la proyección son cuatro problemas distintos** y hoy están entrelazados. Separarlos es lo que hace que una fuente nueva sea un adaptador en vez de una cirugía.
3. **La migración se gana en la fase en sombra.** Es la única que no produce nada visible, y es la que decide si el resto se puede hacer sin romperle la nómina a nadie.

Y una cuarta, para quien toma el proyecto: **lo que está construido es mejor de lo que parece desde la lista de fallas.** Las decisiones difíciles —idempotencia, el lock de refresco, no reintentar donde reintentar duplica, dejar por escrito por qué se revirtió `toggle_clock`— están bien tomadas. Lo que falta no es criterio: es un modelo de dominio que deje de pelear contra la API remota.
