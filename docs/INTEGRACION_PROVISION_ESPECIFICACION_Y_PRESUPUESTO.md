# Integración Provision-ISR ↔ FlowTime — Especificación técnica y presupuesto

**Fecha:** 7 de septiembre de 2026
**Estado:** análisis cerrado sobre la documentación recibida. Listo para trabajarse en una sesión aparte.
**Documento padre:** [INTEGRACION_MLA_PROVISION.md](INTEGRACION_MLA_PROVISION.md) — ese sigue siendo el expediente vivo del proyecto (minutas, decisiones comerciales, bitácora). Éste es el anexo técnico y económico.

> **Cómo usar este archivo en otro chat:** las secciones 3 a 8 son el contrato de trabajo — lo que la cámara entrega, lo que hay que construir y lo que se reutiliza. La sección 9 es la lista de incógnitas que **no se pueden cerrar sin una cámara en banco**; no arranques a codificar los módulos marcados 🔒 sin resolverlas. Las secciones 10-12 son el presupuesto.

---

## 1. Qué se revisó, y qué no

Material recibido (Google Drive, carpeta **Integración MLA**, subida el 2026-09-07):

| Archivo | Qué es | ¿Revisado? |
|---|---|---|
| `Provision-ISR-API-ShortPolling_202508_2.pdf` | HTTP API Protocol User Guide v1.9 (rev. 2024-08). El API de configuración y consulta. ~3,700 líneas | Índice completo + §1.3 (protocolo y autenticación) + §10.1 (inventario de comandos de rostro). **No** se leyó el cuerpo completo |
| `Provision-ISR-API-LongPolling-support metadata.pdf` | Protocolo de suscripción a eventos. **El documento clave** | Índice + §1 (flujos) + §2.1 SetSubscribe + **§2.5.10 VFD_MATCH completo** |
| `Provision-ISR Alarm Server and HTTP POST.pdf` | Los dos mecanismos de push de la cámara | Índice + §1 (Alarm Server IPC) + §2 (NVR) + §3 (HTTP POST) + §3.12 (VFD) |
| `Provision-ISR-API-ShortPolling_202302_...pdf` | Versión anterior (feb 2023) del mismo manual | No revisado — sirve para diffear cambios de API (ver riesgo R4) |
| `Provision-ISR API Tool User Guide.pdf` + `ApiTool.exe` | Herramienta de pruebas Windows (MFC/VS2010) | No revisado a fondo. **Es una herramienta de banco, no una librería** |
| `Latest SDK Versions C# + CPP.zip` (121 MB) | `Windows C# 1.2.1.2725` y `NetSdk.cpp.win.1.2.1.2791` | Sólo estructura: ambos traen `Windows/`, `include/`, `Doc/` |

**Conclusión sobre los SDKs:** confirman por evidencia lo que ya se había decidido a priori. Son binarios **nativos de Windows** (C# y C++). No hay build para Linux ni binding para PHP. Usarlos obligaría a montar un agente Windows dentro de la red del cliente, fuera de la aplicación. **Se mantiene la postura de no usarlos.** Quedan como referencia del modelo de datos.

---

## 2. La buena noticia primero

La documentación es **mucho mejor de lo esperado** y resuelve tres de los cuatro entregables críticos que estaban pendientes en el expediente:

- **E1 (documentación de APIs)** — entregado y completo.
- **E3 (¿hay webhooks?)** — **sí**. Hay dos mecanismos de push distintos. Detalle en §4.
- **E7 (estatus de registro biométrico por usuario)** — **sí, existe**: `GetTargetFace` lista los rostros dados de alta en la cámara. La función de "detectar empleados sin rostro registrado" es construible.

Sigue faltando **E2** (archivo de export de ejemplo) — pero ya no es crítico: la API de eventos sustituye al export por archivo, y el contrato de datos está documentado campo por campo (§5).

---

## 3. El hallazgo central: detección ≠ reconocimiento

La cámara emite **dos eventos distintos** y sólo uno sirve para asistencia:

| Evento | Enum | Qué significa | ¿Sirve? |
|---|---|---|---|
| Face **Detection** | `VFD` / `vfdAlarm` | "Se vio una cara" | ❌ **No.** El payload trae coordenadas, pose, sexo estimado, calidad de imagen y fotos — pero **ningún identificador de persona**. Es anónimo por diseño |
| Face **Match** | `VFD_MATCH` / `vfdMatchAlarm` | "Se reconoció a *esta* persona del padrón" | ✅ **Sí.** Trae `personId`, `name`, `similarity` y `matchResult` |

Esto es lo primero que hay que verificar en campo: **si las cámaras de MLA están configuradas en modo detección y no en modo reconocimiento con padrón cargado, no hay integración posible** — no porque falte software nuestro, sino porque la cámara no sabe quién es la persona.

### 3.1 El segundo hallazgo: la cámara no sabe la dirección

**Decisión de alcance (7 sep):** FlowTime **no almacena datos faciales**. Recibe el evento, toma el identificador y la hora, y descarta todo lo demás. Ver §8.2/M8 — cambia bastante el proyecto, y para bien.

Pero al confirmarse que se quieren **entradas y salidas** (no sólo entradas), aparece un problema que no estaba en el radar:

**`VFD_MATCH` no dice si la persona entra o sale.** El evento dice literalmente "se reconoció a la persona X a las HH:MM en la cámara Y". No hay campo de dirección, ni código de estado, ni nada equivalente. Esto no es una carencia de Provision: una cámara de reconocimiento facial reconoce rostros, no cruces de umbral.

Y en FlowTime el `check_type` sale **exclusivamente** del código de estado del equipo — `ClientAttendanceConfig::resolveCheckType()` mapea 0→`check_in`, 1→`check_out`, etc. Con un ZKTeco eso funciona porque la persona pulsa F1/F2 antes de poncharse. **Con una cámara no hay nada que mapear.**

Las salidas posibles, en orden de robustez:

| Opción | Cómo | Veredicto |
|---|---|---|
| **Una cámara por dirección** | La cámara de la puerta de entrada siempre genera `check_in`; la de salida siempre `check_out`. Se configura como `default_check_type` en `BiometricSource.settings` (columna JSON que **ya existe** y ya está casteada a array) | ✅ **La correcta.** Simple, explícita, auditable. Requiere que físicamente haya cámaras dedicadas por dirección |
| Primera y última lectura del día | La primera pasada es entrada, la última salida | ⚠️ Sólo válido a posteriori: a las 10 de la mañana no sabes si esa lectura es la última. Rompe el tiempo real |
| Alternancia (impar entra, par sale) | — | ❌ **No.** Es exactamente el tipo de heurística que ya corrompió turnos antes. Una lectura perdida desfasa el resto del día |
| Eventos `AOIENTRY` / `AOILEAVE` de Provision | La cámara sí detecta entrada/salida de un área | ❌ Son anónimos, igual que `VFD`. Saben que alguien cruzó, no quién |

**Consecuencia práctica para MLA:** si en una sede hay una sola cámara en la puerta principal, **no se pueden registrar salidas** por más software que se escriba. Hay que preguntarles cuántas cámaras hay por sede y dónde están montadas, antes de comprometer el alcance de "entradas y salidas". Si resulta que hay una sola por sede, el camino es el modo `checkin_only` + cierre automático — que ya está construido y operando.

Además, el documento *Alarm Server and HTTP POST* **documenta el payload de `VFD` pero no el de `VFD_MATCH`**, aunque `VFD_MATCH` sí aparece en su lista de enums (§3.2.2). El payload completo de `VFD_MATCH` sólo está en el manual de **long polling**. Ver la incógnita I1 en §9 — es la que más peso tiene en el presupuesto.

---

## 4. Las tres arquitecturas posibles

### Opción A — HTTP POST / Alarm Server (la cámara empuja)

La cámara se configura desde su propia interfaz web con la IP y puerto de un "servidor de alarmas". A partir de ahí ella sola hace `POST /SendAlarmData` cada vez que dispara un evento, y `POST /SendKeepalive` como latido periódico (el latido es **obligatorio** en este modo).

- ✅ **No requiere que nuestro servidor alcance la cámara.** Sólo salida a internet desde la cámara. Es el mismo modelo mental que `/iclock/*` con los ZKTeco.
- ✅ El latido nos da `last_ping_at` gratis: misma detección de "equipo caído" que ya tenemos.
- ✅ Configuración de una sola vez por el instalador de Provision.
- ⚠️ **No está documentado que este modo emita `VFD_MATCH` con identidad.** Incógnita I1.

### Opción B — Long polling, `BASE_SUBSCRIBE`

Nosotros mandamos `SetSubscribe` a la cámara declarando a qué eventos nos suscribimos; la cámara entonces empuja `SendAlarmData` a nuestro servidor. La suscripción **caduca** (`terminationTime`) y hay que renovarla con `SetRenew`.

- ✅ `VFD_MATCH` documentado campo por campo. Es el contrato que conocemos con certeza.
- ❌ Requiere que **nosotros alcancemos la cámara** para suscribir y renovar.
- ❌ Es estado de larga duración (suscripción viva, renovación periódica, reconexión). Encaja mal en una app PHP sin proceso residente; habría que meterlo en el scheduler y tolerar ventanas de caída.

### Opción C — Long polling, `REALTIME_SUBSCRIBE` (`GetPullMessages`)

Nosotros consultamos activamente. Mismo problema de alcanzabilidad, más carga, y peor encaje aún.

### Opción D — Sondeo de histórico (`SearchSnapFaceByTime`)

Cada N minutos preguntamos a la cámara "dame los rostros reconocidos entre las X y las Y".

- ✅ **Sin estado, idempotente, reintentable.** Encaja perfecto con nuestro scheduler y con la tesis de "registro local + convergencia" del documento de arquitectura objetivo.
- ✅ Es el mecanismo natural de **backfill** y de reconciliación.
- ❌ Requiere alcanzar la cámara.
- ❌ No es tiempo real.

### Recomendación

**Diseñar para A como camino principal, con D como red de seguridad**, y dejar B como plan de contingencia si I1 sale mal.

Concretamente: la ingesta se construye una sola vez (un parser de `SendAlarmData` con `smartType=VFD_MATCH`) y sirve igual para A y para B, porque **el XML del evento es el mismo**. Lo que cambia es sólo quién inicia la conversación. Eso es lo que permite empezar a construir antes de tener cerrada I1 sin arriesgar retrabajo.

---

## 5. El bloqueador real: alcanzabilidad de la cámara

Todo lo que no sea la Opción A exige que `app.sintelcft.dev` inicie conexiones **hacia dentro** de la red del cliente. Las tres salidas posibles, en orden de preferencia:

1. **Sólo push (Opción A).** Cero conexiones entrantes. Coste operativo cero. Pero perdemos alta de rostros y reconciliación, que son justo los módulos F4 y F5.
2. **Conector ligero en sitio.** Un proceso pequeño en un equipo del cliente que habla con las cámaras en LAN y con nosotros por HTTPS saliente. Es lo que se descartó al hablar del SDK — pero conviene ser honestos: **descartar el SDK no elimina la necesidad del agente si queremos las funciones de gestión.** La diferencia es que este conector lo escribimos nosotros, es chico, y no arrastra dependencias nativas de Windows.
3. **Port forwarding / DDNS en el router del cliente.** La cámara tiene soporte DDNS (§7.4 del manual). **No recomendado**: la autenticación del API es **HTTP Basic sobre HTTP plano** (§1.3.3, RFC 2617 — usuario y contraseña en base64, reversible por cualquiera que vea el tráfico). Exponer eso a internet es entregar las cámaras.

> **Decisión pendiente de Daniel + cliente.** De esto depende si los módulos F4 y F5 entran en alcance. Están presupuestados por separado justamente por eso.

---

## 6. Contrato de datos — evento `VFD_MATCH`

Llega como `POST /SendAlarmData`, `Content-Type: application/xml; charset=utf-8`. Estructura real (extraída de §2.5.10 del manual de long polling):

```xml
<config version="1.7" xmlns="http://www.ipc.com/ver10">
  <types>…</types>
  <smartType type="openAlramObj">VFD_MATCH</smartType>
  <subscribeRelation type="subscribeOption">FEATURE_RESULT</subscribeRelation>
  <currentTime type="tint64">1585307773236197</currentTime>
  <snapTime   type="tint64">1585307772269551</snapTime>
  <snapPicId  type="tuint32">31</snapPicId>
  <matchResult type="boolean">true</matchResult>
  <similarity  type="tint32">82</similarity>
  <livingBody  type="tint32">1</livingBody>
  <temperature type="float">0.00</temperature>
  <albumInfo>
    <personId       type="tint32">1585278715</personId>
    <presonListType type="faceMatchAlarmList">whiteList</presonListType>
    <name type="string"><![CDATA[zhoucc]]></name>
    <sex  type="sexType">female</sex>
    <age  type="tint32">28</age>
    <tel  type="string"><![CDATA[]]></tel>
    <res  type="string"><![CDATA[]]></res>
  </albumInfo>
  <snapInfo>…</snapInfo>
  <snapData>   <ImageData>… Base64Length 96694  …</ImageData></snapData>
  <albumData>  <ImageData>… Base64Length 87338  …</ImageData></albumData>
  <sourceData> <ImageData>… Base64Length 161044 …</ImageData></sourceData>
</config>
```

### Campos que nos importan

| Campo | Uso en FlowTime | Nota |
|---|---|---|
| `snapTime` | **`occurred_at`** | Epoch en **microsegundos**, no segundos. `1585307772269551` → 2020-03-27. Dividir entre 1e6 y convertir a `America/Mexico_City` |
| `albumInfo.personId` | **`employee_code`** | Es la llave del mapeo. Equivale al PIN del ZKTeco |
| `matchResult` | Filtro duro | Sólo `true` genera marcaje |
| `similarity` | Umbral configurable | 0-100. Por debajo del umbral → se registra pero **no** se despacha a Factorial |
| `livingBody` | Antifraude | Detección de vida. Sin esto, una foto en un celular ficha por ti |
| `presonListType` | Filtro | Sólo `whiteList`. `strangerList` y `blackList` se descartan |
| `albumInfo.name` | Sólo para mostrar en pantalla | **Nunca** como fallback de identificación. Regla ya establecida en el expediente y aquí se ratifica |
| `albumInfo.res` | Candidato interesante | Campo libre de texto en la ficha de la persona. **Si `AddTargetFace` permite escribirlo, podríamos guardar ahí el ID de empleado de Factorial y ahorrarnos una tabla de mapeo.** Verificar en F0 |
| `snapData` / `albumData` / `sourceData` | Descartar o archivar aparte | Ver abajo |

### El problema del tamaño — acotado por decisión de alcance

Sumadas, las tres imágenes del ejemplo pesan **~345 KB de base64 por evento**. La decisión de **no almacenar nada facial** convierte esto de problema de almacenamiento en problema de ingesta, que es mucho más chico:

- **Nada de esto se persiste.** Los bloques `<ImageData>` se descartan **en el parser, antes de tocar la base de datos**. En `raw_payload` entra el XML ya sin imágenes: ~2 KB en vez de 345 KB. Sin S3, sin política de retención, sin crecimiento de MySQL, sin respaldos más pesados. El costo de infraestructura de la integración es **cero**.
- **Pero la cámara sí nos las manda igual**, queramos o no. Así que el request sigue pesando ~345 KB y hay que dimensionar la entrada: `post_max_size` de PHP está en **8M** y alcanza, pero `client_max_body_size` de nginx **no está declarado** en el vhost de la app, de modo que rige el default de 1 MB y un evento con fuente a 1080×1920 lo roza. **Hay que declararlo explícitamente** — si no, el síntoma sería un 413 intermitente que se ve como "a veces no llegan marcajes".
- Por eso la incógnita **I3 sube de valor**: si `subscribeRelation = ALARM` entrega el evento sin imágenes, el payload baja a ~2 KB en origen y el problema desaparece por completo, en lugar de resolverse tirando bytes que ya viajaron.
- El servidor real es **t3.medium** (2 vCPU, 4 GB), no t3.micro como dice `CLAUDE.md`. Con el volumen previsto (~300 eventos/día, pico ~13/min) sobra.

---

## 7. Inventario de comandos útiles del API

Todos son `POST http://<host>[:port]/<comando>` con `Authorization: Basic base64(user:pass)`.

**Gestión del padrón de rostros (§10.1 del manual ShortPolling):**

| Comando | Para qué lo queremos |
|---|---|
| `AddTargetFace` | Alta de un empleado con su foto. Es el espejo de nuestro `biometric:push-users` |
| `EditTargetFace` / `DeleteTargetFace` | Alta/baja/cambio sincronizados desde Factorial |
| `GetTargetFace` | **Listar quién tiene rostro registrado** → resuelve E7: detectar empleados sin enrolar |
| `GetTargetFaceGroups`, `AddTargetFaceGroup`, `EditTargetFaceGroup`, `DeleteTargetFaceGroup` | Agrupar por sede/turno si hace falta |
| `SearchSnapFaceByTime` | **Backfill y reconciliación.** Traer los reconocimientos de una ventana de tiempo |
| `SearchSnapFaceByKey` | Buscar por persona. Útil para resolver disputas puntuales |
| `GetSmartVfdConfig` / `SetSmartVfdConfig` | Leer y fijar la configuración de detección/reconocimiento, incluida el área de la escena |

**Operación y salud:**

| Comando | Para qué |
|---|---|
| `GetDeviceInfo`, `GetDeviceDetail` | Inventario: modelo, firmware, serie. Alimenta la matriz de modelos (E5) |
| `GetAlarmServerConfig` / `SetAlarmServerConfig` | **Configurar el servidor de alarmas remotamente**, sin entrar a la UI de cada cámara |
| `GetPortConfig` | Descubrir el `longPollingPort` |
| `GetDateAndTime` / `SetDateAndTime` | **Crítico.** El `snapTime` lo pone la cámara. Si su reloj está desfasado, los marcajes salen mal y nadie se entera |

---

## 8. Qué hay que construir en FlowTime

### 8.1 Lo que ya existe y se reutiliza tal cual

Vale la pena tenerlo claro antes de cotizar, porque es la mitad del trabajo que **no** hay que hacer:

- **`attendance_logs`** y toda su máquina de estados (`pending` / `resolved` / `synced` / `failed` / `descartado`).
- **`SyncAttendanceToFactorial`** con su fallback a `updateShift()`. La cámara no cambia nada aguas abajo.
- **`ClientAttendanceConfig`** con `checkin_only` — que es exactamente el requisito estrella de MLA ("sólo entradas") y **ya corre en producción para Outlandish desde el 17 de agosto**.
- **`CloseForgottenShifts`** — el cierre automático de turnos. Igual de válido con cámara que con reloj.
- **La pantalla `clients/{id}/records`** — los marcajes de cámara aparecen ahí sin tocar nada.
- **`BiometricUserSync`** como tabla de mapeo código-externo → empleado de Factorial.
- **`FactorialService`**, OAuth, multi-tenancy: sin cambios.

### 8.2 Lo que hay que construir

**M1 · Endpoint de ingesta autenticado**
`POST /provision/{token}/alarm` y `POST /provision/{token}/keepalive`.
- **Autenticación obligatoria.** Los `/iclock/*` no la tienen porque el protocolo del fabricante no la permite; **aquí sí podemos y por tanto debemos**. Token secreto por dispositivo en la ruta + allowlist de IP de origen. Este endpoint recibe datos biométricos: no puede quedar abierto.
- Declarar `client_max_body_size` en nginx.
- Parser XML tolerante: el namespace `http://www.ipc.com/ver10`, CDATA en los campos de texto, y **descartar los bloques `<ImageData>` antes de persistir**.
- Responder rápido (200) y encolar; nada de trabajo pesado en el request.

**M2 · Driver / perfil de fuente `provision`**
`BiometricSource` hoy está modelado alrededor de ZKTeco. Ya existen `biometric_provider_id`, `push_protocol_profile` y `push_protocol_source` — hay dónde apoyarse. Falta el driver que traduzca eventos Provision al modelo común, y que el gestor de dispositivos sepa mostrar una cámara sin fingir que es un reloj.

**M3 · Identidad y compuertas de calidad**
- `personId` → `BiometricUserSync.external_employee_code` → `factorial_employee_id`.
- Nuevos campos de configuración por cliente: `similarity_min` (arranque sugerido: 80), `require_living_body` (sí), `allowed_list_types` (`whiteList`).
- Un evento que no pasa las compuertas **se guarda con nota explicativa y no se despacha** — mismo patrón que `descartado`, que ya existe y ya se muestra en pantalla.
- Sin mapeo → `pending`, y `attendance:resolve-pending` lo recoge después. Sin código nuevo.
- **`default_check_type` por cámara** en `BiometricSource.settings` (ver §3.1). Es lo que sustituye al código de estado que manda un ZKTeco. La ruta actual `resolveCheckType($status) ?? defaultCheckType($status)` necesita una tercera vía para fuentes que no mandan estado; conviene resolverlo en el driver de §M2 y no metiendo condicionales en `ClientAttendanceConfig`.

**M4 · Convivencia cámara + reloj** ⚠️ *el punto fino*
El expediente ya anotaba que la restricción única `(biometric_source_id, employee_code, occurred_at)` no protege contra duplicados entre dos fuentes. Precisando después de leer el código: **el anti-duplicados de aplicación tampoco protege, pero por otra razón.**

`applyDedupeWindow()` y la regla `once_per_day_clients` sí consultan por **cliente**, no por dispositivo (`AttendanceLog::where('client_id', …)`), así que el alcance es el correcto. El problema es que comparan por **`employee_code`**, y el PIN del ZKTeco y el `personId` de Provision son **códigos distintos para la misma persona**. Dos fuentes → dos códigos → el anti-duplicados no los ve como la misma persona y el duplicado pasa igual.

La corrección es acotada y vale la pena hacerla bien: **normalizar la comparación a `factorial_employee_id`** cuando esté resuelto, cayendo a `employee_code` cuando no. Eso hace que toda la maquinaria anti-duplicados construida en septiembre —la ventana anti-rebote y la regla de una lectura por día— cubra la cámara **sin escribir reglas nuevas**. Es el mejor retorno de esfuerzo de toda la integración.

**M5 · Alta de rostros desde Factorial** 🔒 *depende de alcanzabilidad*
`AddTargetFace` / `EditTargetFace` / `DeleteTargetFace` + pantalla de enrolamiento y de "empleados sin rostro registrado" vía `GetTargetFace`.

**M6 · Reconciliación** 🔒 *depende de alcanzabilidad*
Comando programado que consulta `SearchSnapFaceByTime` sobre la ventana de las últimas N horas y rellena lo que el push haya perdido. Es la aplicación directa de la tesis de convergencia del documento de arquitectura objetivo, y es la respuesta correcta al riesgo de "se cayó el push y nadie se enteró".

**M7 · Observabilidad**
- Latido → `last_ping_at`, y alerta cuando una cámara deja de latir. *(El incidente de Outlandish de hoy mismo —el equipo dejó de reportar a las 08:02 sin un solo error en el log— es exactamente el escenario que esto evita.)*
- Contador de eventos descartados por umbral, para poder afinar `similarity_min` con datos y no a ojo.
- Verificación de deriva de reloj de la cámara contra la hora del servidor.

**M8 · Privacidad — resuelto por diseño, no por proceso**
Decisión de alcance del 7 de septiembre: **FlowTime no almacena datos faciales.** Ni imágenes, ni plantillas, ni vectores. Lo único que persiste es `personId` (un entero), la marca de tiempo y la cámara de origen.

Esto no es un detalle de implementación, es la mejor decisión del proyecto y conviene entender por qué:

- El tratamiento biométrico —capturar el rostro, generar la plantilla, compararla contra el padrón— **ocurre íntegramente dentro de la cámara**, en las instalaciones del cliente. Nunca sale de ahí.
- Lo que cruza a FlowTime es *"la persona 1585278715 fue reconocida a las 07:14"*. Un identificador y una hora. Eso es un dato personal común, del mismo tipo que el PIN de un reloj checador, y **no** un dato biométrico sensible.
- En consecuencia, las obligaciones reforzadas de la LFPDPPP sobre datos sensibles —consentimiento expreso y por escrito, aviso de privacidad específico, medidas de seguridad reforzadas— **recaen en el cliente como responsable del padrón facial**, no en nosotros. Nosotros somos encargados de un identificador.
- **Reduce superficie de riesgo real:** una brecha en FlowTime no expone rostros de nadie, porque no los tenemos. No hay nada que cifrar en reposo, nada que purgar, ningún derecho ARCO que nos obligue a borrar imágenes.

**Regla de implementación, no negociable:** el parser descarta `<snapData>`, `<albumData>` y `<sourceData>` **antes** de cualquier escritura. No "los guardamos y luego los borramos". No llegan nunca a disco. Conviene una prueba automatizada que falle si algún día alguien persiste un campo base64 — es el tipo de regresión que entra sin querer y no se nota hasta que importa.

> ⚠️ **Ojo con la comparación contra lo que ya tenemos.** Sería natural decirle al cliente "es igual que con los relojes checadores, ahí tampoco guardamos nada biométrico". **Eso no es cierto hoy.** Los ZKTeco mandan la tabla `BIODATA` y `IclockController::handleBiodata()` la guarda completa en `biometric_sources.biodata_cache` — incluidas las **plantillas** de huella/rostro (`Tmp=apUBEBgEK3wBAA0AAa3kAAgJ...`), en claro, en MySQL. Medido el 2026-09-07: **12 equipos con datos, hasta 46 KB cada uno**, el más antiguo del 4 de agosto. Se captura para la función de clonado de equipo (`clone_target_id` → `push_biodata`), pero **nada lo purga**: se llama `cache` y se comporta como almacenamiento permanente. Ver §8.3.

Sí conviene que el cliente prepare su aviso de privacidad y sus consentimientos, porque **su** obligación existe. Pero es trabajo suyo y de su área de RH, y hay que decírselo claro para que no se descubra tarde: suele ser el paso que más tiempo toma y no depende de nada técnico.

---

### 8.3 Deuda de privacidad preexistente (fuera del alcance de esta integración)

Detectado al analizar este proyecto, pero **es un tema del sistema actual, no de Provision.** Se documenta aquí porque afecta lo que se le puede afirmar al cliente.

`biometric_sources.biodata_cache` guarda plantillas biométricas de huella y rostro que envían los ZKTeco, en texto plano, sin caducidad y sin cifrado — a diferencia de `factorial_connections.access_token`, que sí está cifrado. Bajo la LFPDPPP una plantilla biométrica **sí** es dato personal sensible, así que hoy Sintelc es responsable de un tratamiento sensible que probablemente no está declarado en ningún aviso de privacidad.

La corrección es barata y conviene hacerla antes de firmar con MLA, porque es incómodo prometer "diseño sin datos biométricos" mientras el sistema actual los acumula:

**Verificado en producción el 2026-09-07, y el resultado cambia la solución:** el clonado es **bajo demanda**. `startClone()` encola `DATA QUERY BIODATA` al equipo origen y `handleBiodata()` reenvía al destino cuando llega la respuesta. **No necesita una caché permanente para funcionar.**

Los números: `clone_target_id` está en NULL en los 32 equipos, se emitió **un solo** `query_biodata` en toda la historia (2026-07-02) y **`push_biodata` nunca se ha emitido**. O sea, la función se probó una vez en julio y no se ha vuelto a usar — y la mitad de empuje nunca llegó a dispararse, cosa que conviene verificar antes de confiar en ella.

Mientras tanto `biodata_cache` sí tiene datos, con fechas del 4 de agosto al 4 de septiembre. **Se está llenando solo:** los equipos mandan `BIODATA` por su cuenta durante su sincronización y `handleBiodata()` guarda lo que llegue, sin que nadie lo haya pedido y sin que nada lo consuma.

Ese es el punto: **no acumulamos plantillas porque el clonado las necesite, sino porque el endpoint guarda todo lo que le empujan.** La corrección es más barata y además mejora la función:

1. **Guardar sólo cuando hay un clonado en curso** (`clone_target_id` no nulo). Si no, ignorar el `BIODATA` entrante.
2. **Purgar al terminar el clonado**, más un barrido de lo huérfano y una limpieza única de las 12 filas actuales.
3. **Cifrar la ventana transitoria** (`encrypted:array`) — con la vida útil reducida a minutos, es defensa en profundidad, no el arreglo principal.

Estimación: **4–8 horas**. **No se cobra al cliente:** es deuda propia del producto actual, no parte de esta integración.

---

## 9. Incógnitas que hay que cerrar antes de comprometer alcance

| # | Incógnita | Por qué importa | Cómo se cierra |
|---|---|---|---|
| **I1** | ¿El modo HTTP POST autónomo emite `VFD_MATCH` con `albumInfo`, o sólo `VFD` anónimo? | **Es la incógnita que define la arquitectura.** Si la respuesta es "sólo VFD", la Opción A muere y hay que ir a B o D, con todo lo que arrastran | Cámara en banco + un listener que registre lo que llegue. También pregunta directa a Provision |
| **I2** | ¿Las cámaras de MLA ya tienen padrón de rostros cargado y reconocimiento activo? | Sin padrón no hay identidad, y el enrolamiento inicial de ~500 personas es un proyecto en sí mismo | Inspección de una cámara en sitio con `GetTargetFace` |
| **I3** | ¿Se puede pedir el evento sin las imágenes base64? | Ya no es un problema de almacenamiento —no guardamos nada— pero sí de ingesta: baja el request de 345 KB a ~2 KB en origen en vez de tirar bytes que ya viajaron | Probar `subscribeRelation = ALARM` |
| **I4** | ¿`AddTargetFace` permite escribir el campo `res`? | Si sí, guardamos ahí el ID de Factorial y nos ahorramos el mapeo manual de cada empleado | Banco |
| **I9** | **¿Cuántas cámaras hay por sede y dónde están montadas?** | Determina si "entradas y salidas" es siquiera posible (§3.1). Con una sola cámara por sede sólo hay entradas, y el camino es `checkin_only` + cierre automático — que ya existe | Preguntar a MLA. **No requiere banco ni a Provision: se puede resolver esta semana** |
| **I5** | ¿Qué modelo y firmware exactos hay en MLA? | El manual cubre una familia amplia; no todos los modelos traen todos los comandos (E5 del expediente) | `GetDeviceInfo` sobre las cámaras reales |
| **I6** | ¿Alcanzabilidad? Push puro, conector en sitio, o VPN | Define si M5 y M6 existen | Conversación con el área de TI de MLA |
| **I7** | ¿MLA = MyNekMan = Outlandish? | Sigue abierta desde el 31 de agosto. Si es el mismo `client_id 4`, media integración ya está hecha y el precio debería reflejarlo | Preguntar a Joaquín |
| **I8** | ¿`app.sintelcft.dev` termina TLS en el origen o detrás de un proxy? | El vhost de nginx sólo escucha en :80. Firmware viejo de cámara suele fallar con TLS moderno; hay que saber qué puede hablar la cámara | Revisión de infraestructura (1 h) |

---

## 10. Estimación de esfuerzo

Horas de desarrollo senior, incluyendo pruebas y documentación.

| Fase | Alcance | Horas |
|---|---|---|
| **F0** | Laboratorio: cerrar I1–I5 e I8, capturar payloads reales, memo de decisión de arquitectura | 16 – 24 |
| **F1** | M1 + M2 — endpoint autenticado, parser (con descarte de imágenes), driver de fuente, nginx/PHP | 20 – 28 |
| **F2** | M3 — identidad, compuertas de calidad, `default_check_type` por cámara, configuración y pantalla | 20 – 28 |
| **F3** | M4 — convivencia cámara/reloj, anti-duplicados normalizado a identidad | 12 – 20 |
| **F6** | M7 — observabilidad, alertas de cámara caída, deriva de reloj, pruebas | 14 – 20 |
| **F7** | Piloto en producción en una sede, monitoreo y ajuste de umbrales | 16 – 24 |
| | **Subtotal núcleo** | **98 – 144** |
| **F4** 🔒 | M5 — alta de rostros desde Factorial + pantalla de no enrolados | 24 – 32 |
| **F5** 🔒 | M6 — reconciliación por `SearchSnapFaceByTime` | 16 – 24 |
| | **Subtotal opcionales** | **40 – 56** |
| | **Total completo** | **138 – 200** |

*Revisado el 7 de septiembre tras acotar el alcance a "sólo identificadores, sin datos faciales". La baja es modesta (~6%) y conviene ser honesto sobre por qué: el trabajo nunca estuvo principalmente en las imágenes. Se va la plumbing de S3, retención y control de acceso a fotos; entra la configuración de dirección por cámara.*

🔒 = sujeto a resolver I6 (alcanzabilidad).

No incluido y hay que contemplarlo aparte: el **enrolamiento inicial de rostros** (tomar y cargar la foto de cada empleado). Con ~500 personas, aun a 2 minutos por persona son ~17 horas de trabajo operativo. Es del cliente o se cotiza como servicio.

---

## 11. Presupuesto propuesto

**Supuesto de tarifa: $800 MXN/hora.** Es el único número que hay que ajustar; todo lo demás se recalcula solo.

### 11.1 Desarrollo

| Concepto | Horas | Importe (MXN) |
|---|---|---|
| **F0 — Laboratorio y cierre de incógnitas** *(precio fijo)* | 20 | **$16,000** |
| **Núcleo F1+F2+F3+F6+F7** | 98 – 144 | **$78,000 – $115,000** |
| **Opcionales F4+F5** | 40 – 56 | **$32,000 – $45,000** |
| **Total si entra todo** | 138 – 200 | **$110,000 – $160,000** |

### 11.2 Cómo estructurarlo comercialmente

Lo importante: **no cotizar el proyecto completo a precio cerrado antes de F0.** La incógnita I1 puede cambiar la arquitectura entera, y comprometer un precio fijo sin resolverla es aceptar un riesgo que no está cuantificado. La forma limpia es:

1. **Fase 0 en firme: $16,000 MXN.** Entregable: memo de arquitectura con payloads reales capturados, respuesta a I1–I5, y la cotización cerrada de la fase 2. **Acreditable al total** si el cliente sigue adelante.
2. **Fase 1 a precio cerrado tras F0.** Con las incógnitas resueltas, la horquilla de 104-152 h se aprieta a un número. Estimación de trabajo: **$95,000 MXN** si I1 sale favorable (Opción A limpia); **$120,000** si hay que ir a B o D con conector en sitio.
3. **Módulos opcionales**, cotizados por separado cuando se decida la alcanzabilidad.

### 11.3 Como producto recurrente

Se pidió cotizarlo "como un extra". La estructura que lo sostiene en el tiempo:

| Concepto | Precio sugerido | Nota |
|---|---|---|
| Activación del módulo Reconocimiento Facial (por cliente) | $25,000 MXN único | Configuración, mapeo inicial, calibración de umbrales, capacitación |
| Alta por cámara adicional | $2,500 MXN | Configuración y pruebas de cada equipo nuevo |
| Mensualidad por cámara | $700 MXN/mes | Monitoreo, alertas, soporte, mantenimiento ante cambios de API de Provision |
| Enrolamiento asistido de rostros | $35 MXN/empleado | Opcional. ~$17,500 para 500 personas |

Con 4 cámaras en un cliente eso es **$2,800 MXN/mes** recurrentes. El desarrollo se amortiza en unos 3 años con un solo cliente — o en menos de un año si el módulo se vende a tres. **Éste es el argumento para construirlo bien y no como un parche para MLA:** el trabajo de M1 a M4 es reutilizable para cualquier cliente con cámaras Provision.

### 11.4 El piso: hasta dónde se puede bajar

Alcance mínimo que **no transfiere riesgo a nosotros**:

| Incluye | Horas |
|---|---|
| F0 laboratorio (no se puede saltar: es la incógnita) | 16 – 20 |
| M1 endpoint autenticado + parser + nginx/PHP | 20 |
| M2 driver mínimo + `default_check_type` por cámara | 10 – 12 |
| M3 mapeo de identidad + compuertas (`matchResult`, `similarity`, `livingBody`, `whiteList`) | 10 – 14 |
| Alerta de cámara caída (el latido la deja casi gratis) | 4 |
| Despliegue y piloto en una sede | 10 – 14 |
| **Piso** | **70 – 84 h → $56,000 – $67,000** |

**Lo que se excluye y qué cuesta excluirlo — hay que ponerlo por escrito en la propuesta:**

- **M4 (anti-duplicados por identidad).** Sólo es seguro omitirlo si la cámara **sustituye** al reloj en esa sede. Si conviven, los duplicados llegan a Factorial y corrompen nómina. **Esto no se recorta si hay convivencia**, a ningún precio.
- Pantalla de enrolamiento y padrón (F4), reconciliación (F5), observabilidad a fondo.

**Debajo de ~$45,000 el proyecto deja de tener sentido**: la carga de soporte de un sistema del que depende la nómina de 500 personas se come el margen. Ahí ya no estás descontando, estás subsidiando.

### 11.5 La palanca correcta no es el alcance, es la estructura

Si hace falta un número de entrada más bajo, **no se recorta lo que protege la nómina** — se financia el desarrollo con la recurrencia:

| | Entrada alta | **Entrada baja (recomendado)** |
|---|---|---|
| Fase 0 | $16,000 | $16,000 |
| Implementación | $95,000 | **$35,000** |
| Mensualidad por cámara | $700 | **$1,200** |
| Compromiso mínimo | — | **24 meses** |
| **Total a 24 meses, 4 cámaras** | $178,200 | **$166,200** |

Casi el mismo ingreso total, pero el cliente entra con **$51,000 en vez de $111,000** y nosotros ganamos ingreso predecible en lugar de un pago único. Requiere cláusula de permanencia mínima o penalización por salida anticipada; sin eso, el modelo no se sostiene.

### 11.6 Infraestructura

Casi nula, si se descartan las imágenes:

- Tráfico de entrada a EC2: **sin costo** en AWS.
- Sin imágenes: el evento pesa ~2 KB. Impacto nulo.
- **Cero almacenamiento**, por decisión de alcance: no se guardan imágenes en ningún lado, ni siquiera temporalmente.
- El t3.medium actual aguanta el volumen previsto (~300 eventos/día, pico de ~13/min). **No hay que escalar la instancia.**
- En resumen: **la integración no agrega costo de infraestructura.** Eso es consecuencia directa de no almacenar nada facial.

---

## 12. Plan de arranque sugerido

| Orden | Acción | Bloquea a |
|---|---|---|
| 1 | Confirmar con Joaquín si MLA = MyNekMan = Outlandish (I7) | Todo el alcance y el precio |
| 2 | Preguntar a Provision por I1 e I3 — por escrito, con la cita del manual | La arquitectura |
| 3 | Conseguir **una cámara en banco** o acceso remoto a una de MLA | F0 |
| 4 | Conversación con TI de MLA sobre alcanzabilidad (I6) | F4 y F5 |
| 5 | Ejecutar F0 y cerrar la cotización de la fase 2 | El contrato |
| 6 | **Preguntar a MLA cuántas cámaras hay por sede y dónde (I9)** — de esto depende si "salidas" es posible | El alcance de entradas+salidas |
| 7 | En paralelo y sin depender de nada: **M4** (anti-duplicados normalizado a `factorial_employee_id`) | Nada. Es útil hoy aunque la integración no avance |
| 8 | En paralelo: **cifrar y purgar `biodata_cache`** (§8.3) | Conviene cerrarlo antes de hablar de privacidad con el cliente |

> El punto 6 es el que conviene arrancar ya. Es trabajo acotado, no depende de Provision, mejora la plataforma con o sin cámaras, y es la pieza que hace que toda la maquinaria anti-duplicados existente cubra una segunda fuente el día que llegue.

---

## 13. Bitácora de este anexo

- **2026-09-07** — Documento creado tras recibir SDKs y documentación de Provision. Se revisó el material listado en §1. Confirmado: existen webhooks (E3), existe consulta de padrón (E7), los SDKs son Windows-only y se ratifica no usarlos (E4). Abierta la incógnita I1 como determinante de la arquitectura.
- **2026-09-07 (tarde)** — Acotado el alcance: **FlowTime no almacenará datos faciales, sólo identificadores.** Consecuencias: M8 se resuelve por diseño, el costo de infraestructura cae a cero, el total baja a $110–160k. Al confirmarse que se quieren **entradas y salidas** aparece §3.1: `VFD_MATCH` no indica dirección, así que hace falta una cámara dedicada por dirección (nueva incógnita I9). Detectada además la deuda preexistente de `biodata_cache` (§8.3), ajena a esta integración pero relevante para lo que se le puede afirmar al cliente.

