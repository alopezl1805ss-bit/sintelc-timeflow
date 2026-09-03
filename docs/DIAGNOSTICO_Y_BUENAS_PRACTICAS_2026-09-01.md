# Diagnóstico de rendimiento y propuesta de buenas prácticas

**Sintelc FlowTime** (`app.sintelcft.dev`) — EC2 t3.micro, us-east-2
**Fecha:** 2026-09-01
**Alcance:** rendimiento del servidor, seguridad y administración de endpoints, resiliencia del flujo hacia Factorial.

> Este documento es **una propuesta**. Nada de lo que aquí se describe se ha aplicado al servidor.
> El diagnóstico se hizo únicamente con comandos de lectura.

---

## 1. Resumen ejecutivo

**El servidor no está saturado hoy.** Con ~37.000 peticiones/día y un load average de 0.64 sobre 2 vCPU, hay margen de sobra en CPU, RAM y disco.

**El riesgo no es la capacidad del servidor: es el diseño del canal hacia Factorial.** Hay un único worker de cola procesando trabajos en serie, con un retardo fijo de 2 segundos entre registros y **cero reintentos**. Eso significa que una carga masiva (un equipo que vuelve de una caída y sube 400 registros bufferizados) se drena a razón de ~1 registro cada 1–2 segundos, y cualquier fallo transitorio de la API de Factorial pierde ese registro de forma permanente.

Los tres hallazgos que importan, en orden:

| # | Hallazgo | Severidad | Evidencia |
|---|---|---|---|
| 1 | **Un solo worker, `tries=1`, sin backoff, retardo serial de 2s** | 🔴 Crítica | 27% de fallos hoy (102 de 383 registros); 451 `failed` acumulados |
| 2 | **Endpoints `/iclock/*` sin autenticación, sin rate limit y sin límite de tamaño** | 🔴 Crítica | Cualquiera que conozca un número de serie puede inyectar asistencia |
| 3 | **Logging a nivel INFO de cada poll de dispositivo** | 🟠 Alta | 95 MB de log en un solo día; 33.570 polls/día registrados uno a uno |

---

## 2. Diagnóstico: estado actual medido

### 2.1 Recursos del sistema

```
CPU        2 vCPU Intel Xeon 8175M @ 2.50GHz
Load       0.64 / 0.21 / 0.07        →  ~32% de 1 núcleo. Holgado.
RAM        3.7 GB total | 2.2 GB en uso | 1.5 GB disponible
Swap       761 MB, prácticamente sin usar (44 KB)  →  no hay presión de memoria
Disco      13 GB | 8.4 GB usados (67%) | 4.2 GB libres
Inodos     15% usados
```

**Veredicto:** sano. El único vector de agotamiento a medio plazo es el **disco** (ver §2.5).

### 2.2 Stack

```
Ubuntu · nginx 1.24.0 · PHP 8.2.33 · PHP-FPM 8.2 · MySQL · Laravel 12.65.0
APP_ENV=production · APP_DEBUG=false · LOG_LEVEL=info
QUEUE=database · CACHE=database · SESSION=database
Redis instalado en config pero NO en uso (todo va a MySQL)
```

**Nota:** `REDIS_HOST` está en el `.env` pero no hay proceso Redis escuchando. Cola, caché y sesiones van todas a la misma base de datos MySQL — cada job encolado, cada lectura de caché y cada request autenticado son escrituras/lecturas a MySQL.

### 2.3 Distribución del tráfico (1 sep 2026)

| Endpoint | Peticiones | % |
|---|---:|---:|
| `/iclock/getrequest` | 33.570 | **90,7%** |
| `/iclock/cdata` | 1.138 | 3,1% |
| `/livewire/update` | 430 | 1,2% |
| `/iclock/devicecmd` | 51 | 0,1% |
| Escaneo externo (404) | 1.413 | 3,8% |
| **Total** | **37.018** | |

Carga por hora: constante entre 2.700 y 3.500 req/h, sin picos diurnos — **los equipos hacen polling 24/7**. Pico máximo por minuto: 464 req.

**Cada uno de esos 33.570 polls arranca un ciclo completo de Laravel** (bootstrap, providers, router, sesión desactivada pero contenedor completo) para devolver, en la mayoría de los casos, una respuesta vacía de 2 bytes. Es el consumidor dominante de CPU del servidor.

### 2.4 Estado de la cola y del sincronizado

```
jobs pendientes:     0
jobs reservados:     0
failed_jobs (tabla): 0    (pero 120 filas históricas, 2.5 MB)
Duración típica de un job SyncAttendanceToFactorial: ~1 segundo (llamada HTTP a Factorial)
```

Estado de `attendance_logs` (14.501 filas totales):

| Estado | Registros |
|---|---:|
| `synced` | 12.638 |
| `pending` | **1.278** |
| `failed` | **451** |
| `descartado` | 133 |
| `local` | 1 |

**El `pending` más antiguo es del 2026-06-02** — lleva tres meses sin resolverse. Son registros que llegaron sin empleado mapeado y nunca se reconciliaron.

Fallos por día (últimos días):

```
2026-09-01: 102   ← sobre 383 registros del día = 27% de tasa de fallo
2026-08-31:  45
2026-08-29:  25
2026-08-27:  70
```

Causas principales de `failed`:

```
140  Turno ya editado por biométrico SFT para check_in
112  Sin turno abierto para sobreescribir (No fue posible registrar la salida)
 36  Sin turno abierto para sobreescribir (No fue posible registrar la asistencia)
 10  Forbidden by Attendance::EmployeePolicy
  8  Imposible empezar fichaje. Ya existe un turno en curso
  7  se solapa con el turno que empieza en...
  7  Directo: Ya existe un turno en curso | Overwrite: HTTP request failed  ← fallo de red, no de negocio
  6  check_type no soportado: unknown
```

**Lectura importante:** la mayoría son conflictos de negocio legítimos de Factorial, no errores de infraestructura. **Pero** los que dicen `HTTP request failed` son fallos transitorios de red que, con `tries = 1`, se pierden para siempre. Y con la configuración actual **no hay forma de distinguir automáticamente** un "no se puede, es imposible" de un "no se pudo ahora, reinténtalo".

### 2.5 Base de datos

```
innodb_buffer_pool_size          512 MB
Hit ratio del buffer pool        99,977%   (2.598 lecturas a disco de 11,4 M peticiones)
max_connections                  50        (máximo usado histórico: 4)
Slow query log                   OFF       ← no hay visibilidad de queries lentas
long_query_time                  10 s      (demasiado alto aunque se activara)
innodb_flush_log_at_trx_commit   1         (durable, correcto)
```

Tablas por tamaño:

| Tabla | Filas | Tamaño |
|---|---:|---:|
| `device_inventory_users` | **271.561** | **94,8 MB** |
| `attendance_logs` | 13.707 | 9,7 MB |
| `device_commands` | 12.867 | 9,0 MB |
| `factorial_employees` | 1.479 | 3,9 MB |
| `failed_jobs` | 120 | 2,5 MB |

`attendance_logs` está bien indexada (5 índices, incluido el único `uq_attendance_source_pin_time` que da idempotencia al `insertOrIgnore`).

**Problema de crecimiento sin control:** `device_inventory_users` crece ~30.000 filas al día:

```
2026-09-01: 32.244
2026-08-31:  7.463
2026-08-28: 24.347
2026-08-27: 23.269
2026-08-26: 46.238
```

A ~10 MB/día y con 4,2 GB libres, el disco aguanta ~14 meses **si nada más crece**. No es urgente, pero es una bomba de relojería sin política de retención.

### 2.6 Logging

```
storage/logs                     122 MB
laravel-2026-08-31.log            95 MB   ← un solo día
laravel-2026-09-01.log           4,1 MB   (a mitad de día)
/var/log/nginx/access.log        4,8 MB
```

Composición de los 95 MB del 31 de agosto:

```
7.021  ERROR: Unable to encode attribute [device_users] for model [BiometricSource]
6.743  INFO:  ZKTeco CDATA {"sn":"UEED244300146","table":"OPERLOG",...}
~2.600 INFO:  ZKTeco GETREQUEST  × ~20 dispositivos = ~52.000 líneas
```

Dos cosas distintas ocurriendo:

1. **Ruido estructural:** cada poll de cada equipo se escribe a disco a nivel INFO. Son ~52.000 líneas/día que no aportan información diagnóstica (son idénticas).
2. **Un incidente real:** los 7.021 errores de `Unable to encode attribute [device_users]` son el incidente de UTF-8 en OPERLOG ya documentado — un nombre con `Ñ` truncada rompía la serialización JSON del modelo. **Ese error quedó enterrado bajo 88 MB de ruido de polling.** Ese es exactamente el coste de loguear a INFO en producción: cuando pasa algo, no se ve.

### 2.7 Concurrencia web

```
nginx worker_connections          768
PHP-FPM  pm = dynamic
         pm.max_children          12
         pm.start_servers          4
         pm.max_requests         500
memory_limit                     128 M
max_execution_time                30 s
```

Consumo real medido: cada worker PHP-FPM ocupa ~52 MB RSS. Con `max_children = 12` el techo teórico es **~624 MB**, más `mysqld` (547 MB) y el worker de cola (67 MB) ≈ **1,3 GB de pico**. Con 3,7 GB totales hay margen, pero es un margen que se estrecha si se sube `max_children` sin pensar.

**No hay `pm.max_spare_servers` ajustado al perfil real:** el tráfico es constante (polling 24/7), no ráfagas. `pm = static` sería más eficiente aquí que `dynamic`, que gasta ciclos creando y destruyendo hijos.

### 2.8 Errores HTTP observados hoy

```
35.454  200
 1.413  404   ← 100% escaneo automatizado externo
    92  302
    25  500   ← todos en /iclock/getrequest
     8  419   (CSRF expirado en la UI)
```

**25 respuestas 500 en `/iclock/getrequest`.** Cada una es un equipo que no recibió su comando pendiente. No están alertadas en ningún sitio.

---

## 3. Seguridad: hallazgos

### 3.1 🔴 Endpoints `/iclock/*` completamente abiertos

```php
// routes/web.php:84
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class])
    ->prefix('iclock')->middleware('iclock')->group(function () { ... });

// bootstrap/app.php
$middleware->group('iclock', []);   // ← el grupo está VACÍO
```

El grupo `iclock` no aplica **ningún** middleware. Los seis endpoints (`ping`, `getrequest`, `cdata`, `registry`, `push`, `devicecmd`) están expuestos a Internet sin autenticación, sin rate limit y sin límite de tamaño de cuerpo.

**Impacto concreto:** el único "secreto" es el número de serie del equipo, que viaja **en la query string en claro** (`?SN=VGU6255101182`) y por tanto queda escrito en los logs de nginx, en los de Cloudflare y en cualquier proxy intermedio. Quien lo obtenga puede:

- Inyectar fichajes falsos vía `POST /iclock/cdata?table=ATTLOG` → que se sincronizan a Factorial como asistencia real de un empleado.
- Enviar `USERINFO` falsos y corromper el mapeo de empleados.
- Saturar la cola con miles de registros ficticios.

Esto no es teórico: hoy mismo hubo **13 intentos de leer `/.env`** desde escáneres automatizados. El endpoint está siendo sondeado.

### 3.2 🔴 Origen accesible en HTTP plano + `trustProxies('*')`

```
LISTEN  0.0.0.0:80     ← abierto a todo Internet
LISTEN  0.0.0.0:8080   ← abierto a todo Internet
Sin certificado TLS en el servidor (no hay /etc/letsencrypt/live)
```

Las IPs de cliente en los logs son de Cloudflare (`104.22.x`, `172.71.x`, `162.159.x`): **Cloudflare hace de proxy y termina el TLS**, y el origen habla HTTP plano.

Combinado con:

```php
$middleware->trustProxies(at: '*');   // confía en CUALQUIER proxy
```

Resultado: si alguien descubre la IP pública de la instancia (trivial: historiales DNS, certificados, Shodan), puede conectarse directo al `:80`, **saltarse por completo Cloudflare** (WAF, rate limiting, DDoS) y además **falsificar `X-Forwarded-For`** para suplantar cualquier IP de origen, porque Laravel confía en cabeceras de cualquier remitente.

### 3.3 🟠 Sin rate limiting en ninguna capa

- nginx: sin `limit_req_zone` / `limit_req`
- Laravel: `throttle` solo aparece **una vez** en todo el proyecto, en la ruta de verificación de email
- `/iclock/*`: sin límite

Un solo cliente puede abrir tantas conexiones como permita `worker_connections` (768) y agotar los 12 workers de PHP-FPM. **No hace falta un ataque: basta un equipo ZKTeco con firmware defectuoso en bucle de reintentos.**

### 3.4 🟠 Sin límite de tamaño de petición

nginx no define `client_max_body_size` (por defecto 1 MB) y PHP acepta `post_max_size = 8M`. Un `POST /iclock/cdata` de 8 MB con decenas de miles de líneas de ATTLOG se procesa entero, en memoria, dentro del `memory_limit` de 128 MB, en un solo proceso PHP. No hay validación de número máximo de registros por lote.

### 3.5 🟡 Ficheros sensibles con permisos laxos

```
-rw-rw-r--  1 daniel www-data  1538  .env
-rw-rw-r--  1 daniel www-data  1538  .env.bak.20260831_155917
```

Modo `0664`: **legible por cualquier usuario del sistema**. El `.env` contiene la `APP_KEY` (con la que se cifran los tokens OAuth de Factorial de todos los clientes) y las credenciales de MySQL. Además el `.env.bak` está sin trackear en el repositorio y es un duplicado exacto — un fichero de secretos más que proteger, sin necesidad.

`bootstrap/cache/` y `storage/logs/` son `drwxrwsr-x` con el bit setgid, propiedad de `daniel` con grupo `www-data`. Funciona, pero mezcla propiedad de usuario humano y de servicio.

### 3.6 🟡 TLS obsoleto declarado en nginx

```nginx
ssl_protocols TLSv1 TLSv1.1 TLSv1.2 TLSv1.3;
```

TLS 1.0 y 1.1 están deprecados desde 2021. Hoy es inerte (no hay bloque `server` con `listen 443 ssl`), pero es una mina si algún día se añade TLS en el origen sin revisar esta línea.

### 3.7 🟡 Sin fail2ban ni bloqueo de escáneres

```
fail2ban: no instalado
ufw:      sin configurar / sin acceso
```

1.413 peticiones 404 hoy, todas de escaneo automatizado buscando `.env`, `/api/kernels`, `/proxy`, `/fetch`. Ninguna consecuencia para el atacante: puede seguir sondeando indefinidamente. Cada 404 consume un worker de PHP-FPM.

### 3.8 🟡 Sin respaldo automatizado de base de datos

```
/home/daniel/backup-bd-2026-08-31.sql.gz   10 MB   (manual, 31 ago)
```

Un único volcado manual, guardado en el propio disco de la instancia. Si la instancia se pierde, se pierde el respaldo. **No hay backup automatizado ni copia fuera del servidor.**

### 3.9 🟢 Lo que sí está bien

- `APP_DEBUG=false` y `APP_ENV=production` — sin fugas de stack traces.
- `PasswordAuthentication no` en SSH — solo clave pública.
- `unattended-upgrades` activo — parches de seguridad automáticos.
- CSRF activo en toda la UI, exceptuado solo en `iclock/*` (correcto, los equipos no manejan sesión).
- El scheduler **sí está corriendo** (`close-forgotten-shifts` ejecutándose cada hora, verificado en logs de hoy a las 07:00 y 10:00).
- Índice único `uq_attendance_source_pin_time` → los reenvíos de un equipo no duplican registros.
- `MySQL` y `mysqlx` escuchando solo en `127.0.0.1`, no expuestos.
- Refresco de token OAuth protegido con `Cache::lock` por conexión — sin condición de carrera entre workers.

---

## 4. El cuello de botella real: el canal hacia Factorial

Este es el núcleo de la pregunta "¿qué pasa si hay carga masiva?".

### 4.1 Configuración actual

```ini
# /etc/supervisor/conf.d/sintelc-worker.conf
command=php artisan queue:work --sleep=3 --tries=1 --max-jobs=500 --max-time=3600 --memory=128
numprocs=1
```

```php
// app/Jobs/SyncAttendanceToFactorial.php
public int $tries = 1;          // sin reintentos, sin backoff
// no hay $timeout, ni $backoff, ni middleware de rate limit

// app/Services/FactorialService.php
Http::withToken($token)->acceptJson()->timeout(30);
// sin connectTimeout, sin ->retry(), sin manejo de 429
```

```php
// app/Http/Controllers/IclockController.php — handleAttlog()
$delay = 0;
foreach ($insertedIds as $logId) {
    SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
    $delay += 2;                // retardo acumulativo, global al lote
}
```

### 4.2 Qué pasa en una carga masiva — con números

Escenario realista: un equipo pierde conexión un fin de semana y al reconectar sube **400 registros bufferizados**.

| Restricción | Efecto |
|---|---|
| `$delay += 2` acumulativo | El último job del lote se programa a **T+800 s (13,3 min)** |
| 1 solo worker | Los jobs se procesan **en serie**, no en paralelo |
| ~1 s por llamada a Factorial (medido) | Techo real: **~1 registro/segundo** |
| `--sleep=3` | Tras vaciar la cola, 3 s de espera antes de volver a mirar |

**Con un equipo:** ~13 minutos. Tolerable.

**Con tres equipos recuperándose a la vez (1.200 registros):** los retardos son marcas de tiempo absolutas, así que los tres lotes **no se paralelizan** — se acumulan en la misma cola y el único worker los drena en serie. Tiempo total ≈ **1.200 × 1 s ≈ 20 minutos**, y ningún registro de ningún otro cliente avanza mientras tanto. Un incidente en un cliente bloquea a todos.

**Peor caso:** si Factorial responde lento y una llamada se cuelga hasta el `timeout(30)`, ese único worker queda bloqueado 30 segundos. Diez llamadas colgadas = 5 minutos de cola parada, para todos los clientes.

**Y sobre todo:** con `tries = 1`, un `HTTP request failed` transitorio (ya visible 7 veces en los datos de fallo) marca el registro como `failed` **para siempre**. No hay reintento. La asistencia de ese empleado no llega a Factorial y nadie se entera salvo mirando la pantalla de registros.

### 4.3 Diagnóstico

El diseño actual es correcto en intención (el retardo de 2 s existe para garantizar el orden cronológico: `check_out` de ayer antes que `check_in` de hoy) pero **paga ese orden con throughput global y con ausencia total de tolerancia a fallos**.

El orden solo importa **por empleado**. Serializar globalmente para garantizar un orden que solo es necesario a nivel de empleado es el error de diseño de fondo.

---

## 5. Propuesta de buenas prácticas

Ordenada por relación impacto/riesgo. Cada bloque indica qué resuelve y qué riesgo tiene aplicarlo.

---

### FASE 1 — Contención inmediata (bajo riesgo, alto impacto)

#### 1.1 Bajar el nivel de log en producción

**Resuelve:** 95 MB/día de ruido, incidentes reales enterrados, presión de disco.

En `.env`:

```
LOG_LEVEL=warning
```

Y en `IclockController`, degradar los `Log::info` de alta frecuencia a `Log::debug`:

- `ZKTeco GETREQUEST` (línea 39) → `Log::debug`
- `ZKTeco PING` (línea 23) → `Log::debug`
- `ZKTeco CDATA` (línea 87) → `Log::debug`

Mantener a INFO solo los eventos con significado de negocio: `ATTLOG procesado`, `USERINFO recibido`, `DEVICECMD RESULT`.

**Efecto esperado:** de ~95 MB/día a menos de 1 MB/día. Cuando vuelva a pasar algo como el incidente de UTF-8, se verá en la primera pantalla del log.

**Riesgo:** bajo. Se pierde trazabilidad fina del polling; si hiciera falta depurar un equipo concreto, se sube `LOG_LEVEL=debug` temporalmente.

#### 1.2 Rotación y retención de logs de Laravel

**Resuelve:** el disco. Hay 122 MB en `storage/logs` sin política de purga.

En `config/logging.php`, canal `daily`:

```php
'daily' => [
    'driver' => 'daily',
    'path'   => storage_path('logs/laravel.log'),
    'level'  => env('LOG_LEVEL', 'warning'),
    'days'   => 14,          // retener 14 días
    'replace_placeholders' => true,
],
```

**Riesgo:** ninguno.

#### 1.3 Proteger los ficheros de secretos

```bash
chmod 640 /var/www/sintelcft/.env
```

Y eliminar el `.env.bak` una vez confirmado que el `.env` actual es correcto (son idénticos: mismo tamaño, misma fecha). Si se quiere conservar histórico de configuración, que viva **fuera** del directorio de la aplicación y cifrado, no como copia en claro junto al original.

**Riesgo:** bajo. Verificar antes que `www-data` pertenece al grupo del fichero (lo hace: grupo `www-data`).

#### 1.4 Activar el slow query log de MySQL

**Resuelve:** ceguera total sobre queries lentas. Hoy no se puede saber si hay alguna.

```ini
# /etc/mysql/mysql.conf.d/mysqld.cnf
slow_query_log      = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time     = 1
log_queries_not_using_indexes = 0
```

**Riesgo:** bajo, pero requiere reinicio de MySQL → **coordinar ventana**. Con `long_query_time = 1` y el volumen actual, el fichero crecerá muy poco.

---

### FASE 2 — Blindaje de endpoints (el punto más urgente en seguridad)

#### 2.1 Autenticar los dispositivos

**Resuelve:** el hallazgo 🔴 3.1. Hoy el número de serie es la única credencial y viaja en la URL.

Crear un middleware real para el grupo `iclock` (hoy está vacío) que:

1. Verifique que el `SN` existe en `biometric_sources` **y** está `active` (hoy esto se comprueba dentro del controlador, después de arrancar toda la lógica).
2. Verifique un **secreto compartido por dispositivo**, almacenado en `biometric_sources`, comparado con `hash_equals()`.
3. Registre y rechace con 403 cualquier `SN` desconocido, sin ejecutar lógica.

```php
// app/Http/Middleware/AuthenticateBiometricDevice.php
public function handle(Request $request, Closure $next): Response
{
    $sn = $request->query('SN') ?? $request->input('SN');

    $source = BiometricSource::where('serial_number', $sn)
        ->where('status', 'active')
        ->first();

    if (! $source) {
        Log::warning('iclock: SN no reconocido', ['sn' => $sn, 'ip' => $request->ip()]);
        return response('Unauthorized', 401);
    }

    $request->attributes->set('biometric_source', $source);
    return $next($request);
}
```

```php
// bootstrap/app.php
$middleware->group('iclock', [
    \App\Http\Middleware\AuthenticateBiometricDevice::class,
    'throttle:iclock',
]);
```

> ⚠️ **Advertencia de despliegue.** El firmware ZKTeco tiene soporte limitado para cabeceras personalizadas. Antes de exigir un secreto, hay que verificar contra un equipo real qué mecanismo admite (algunos aceptan un parámetro extra en la URL de configuración del servidor, otros no admiten nada). **Aplicar primero solo la validación de SN activo + rate limit** (que no requiere nada del dispositivo) y tratar el secreto compartido como una segunda iteración, probada primero contra un solo equipo.

#### 2.2 Rate limiting por dispositivo

**Resuelve:** 🟠 3.3. Un equipo en bucle de reintentos no puede tumbar el servicio para todos.

En `AppServiceProvider::boot()`:

```php
RateLimiter::for('iclock', function (Request $request) {
    $sn = $request->query('SN') ?? $request->input('SN') ?? $request->ip();
    return [
        Limit::perMinute(120)->by('iclock:'.$sn),   // ~2 req/s por equipo
        Limit::perMinute(600)->by('iclock:global'), // techo global de seguridad
    ];
});
```

Los equipos hacen ~2.600 polls/día = ~1,8/min. Un límite de 120/min deja **60× de margen** sobre el comportamiento normal y aun así corta en seco un bucle descontrolado.

**Riesgo:** medio si el límite se pone demasiado bajo → equipos legítimos rechazados. Empezar en 120/min, medir con `Log::warning` los rechazos durante una semana, y ajustar.

#### 2.3 Rate limiting en nginx (capa previa a PHP)

**Resuelve:** que un 404 de escáner ni siquiera llegue a consumir un worker de PHP-FPM.

```nginx
# /etc/nginx/nginx.conf, dentro de http { }
limit_req_zone  $binary_remote_addr  zone=general:10m  rate=30r/s;
limit_req_zone  $http_cf_connecting_ip zone=perip:10m  rate=10r/s;
limit_conn_zone $binary_remote_addr  zone=conn:10m;
```

```nginx
# en el server block
client_max_body_size 2m;
server_tokens off;

location / {
    limit_req  zone=general burst=50 nodelay;
    limit_conn conn 20;
    try_files $uri $uri/ /index.php?$query_string;
}
```

> **Ojo con Cloudflare:** `$binary_remote_addr` será siempre una IP de Cloudflare, así que limita a Cloudflare entero, no al cliente real. Para limitar por cliente real hay que usar `$http_cf_connecting_ip` **y** el módulo `ngx_http_realip_module` con la lista de rangos de Cloudflare. Esto es lo que hace correcto el punto 2.4.

#### 2.4 Cerrar el origen: solo Cloudflare puede hablar con el servidor

**Resuelve:** 🔴 3.2, el hallazgo de seguridad más grave junto al 3.1.

Tres piezas, en este orden:

**a) Restringir el Security Group de AWS.** Los puertos 80 y 8080 solo deben aceptar tráfico de los [rangos IP publicados de Cloudflare](https://www.cloudflare.com/ips/). Se hace desde la consola de EC2, no en el servidor.

**b) Configurar `real_ip` en nginx** para que la IP registrada sea la del cliente real y no la de Cloudflare:

```nginx
# /etc/nginx/conf.d/cloudflare-realip.conf
# (generar la lista completa desde https://www.cloudflare.com/ips/)
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
# ... resto de rangos IPv4 e IPv6
real_ip_header CF-Connecting-IP;
```

**c) Sustituir `trustProxies('*')` por la lista explícita:**

```php
// bootstrap/app.php
$middleware->trustProxies(
    at: explode(',', env('TRUSTED_PROXIES', '')),
    headers: Request::HEADER_X_FORWARDED_FOR
           | Request::HEADER_X_FORWARDED_HOST
           | Request::HEADER_X_FORWARDED_PORT
           | Request::HEADER_X_FORWARDED_PROTO,
);
```

Con `set_real_ip_from` bien configurado, `TRUSTED_PROXIES` puede quedarse en `127.0.0.1` porque nginx ya habrá reescrito la IP real.

> ⚠️ **Este cambio puede dejar los equipos sin conexión si se hace mal.** Verificar primero, con los logs en la mano, que **todo** el tráfico de dispositivos entra vía Cloudflare y ninguno apunta a la IP directa. Aplicar el Security Group en una ventana de baja actividad y con acceso SSH ya abierto (SSH va por el puerto 22, no afectado).

#### 2.5 Limpiar el TLS declarado

```nginx
ssl_protocols TLSv1.2 TLSv1.3;
ssl_prefer_server_ciphers off;
```

**Riesgo:** ninguno hoy (no hay bloque `443`), pero evita el problema el día que se añada.

#### 2.6 fail2ban para escáneres

```ini
# /etc/fail2ban/jail.local
[nginx-badbots]
enabled  = true
port     = http,https
filter   = nginx-badbots
logpath  = /var/log/nginx/access.log
maxretry = 10
findtime = 60
bantime  = 3600
```

Con `set_real_ip_from` ya configurado (2.4b), fail2ban bloqueará la IP real y no a Cloudflare entero. **Sin ese paso previo, fail2ban puede bloquear un rango de Cloudflare y dejar el servicio inaccesible para todos.** Instalar fail2ban **después** de 2.4.

---

### FASE 3 — Que la información no se atore (rendimiento del canal a Factorial)

Este bloque responde directamente a la pregunta de cargas masivas y flujo constante.

#### 3.1 Separar las colas por criticidad

**Resuelve:** que un lote masivo de un cliente bloquee la sincronización en tiempo real de todos los demás.

```php
// SyncAttendanceToFactorial — fichajes en vivo, máxima prioridad
public function __construct(public int $logId)
{
    $this->onQueue('attendance');
}

// SyncFactorialConnection, ProcessCsvAutoMatch — sincronizaciones pesadas
$this->onQueue('bulk');
```

Y en Supervisor, **dos programas separados**:

```ini
[program:sintelc-worker-attendance]
command=php /var/www/sintelcft/artisan queue:work --queue=attendance --sleep=1 --tries=3 --backoff=10,60,300 --max-jobs=500 --max-time=3600 --memory=192
numprocs=2
user=www-data
autostart=true
autorestart=true
stopwaitsecs=120

[program:sintelc-worker-bulk]
command=php /var/www/sintelcft/artisan queue:work --queue=bulk,default --sleep=3 --tries=3 --backoff=30,120,600 --max-jobs=200 --max-time=3600 --memory=256
numprocs=1
user=www-data
autostart=true
autorestart=true
stopwaitsecs=120
```

**Cálculo de memoria:** 3 workers × ~192 MB de techo = ~576 MB, más PHP-FPM (~624 MB de pico) y MySQL (547 MB) ≈ **1,75 GB**. Sobre 3,7 GB totales queda margen, pero es el límite de lo prudente en un `t3.micro`. **No subir de 3 workers sin migrar a una instancia mayor.**

> ⚠️ `stopwaitsecs=3600` en la configuración actual significa que Supervisor espera **una hora** a que el worker termine antes de matarlo. En un despliegue eso deja código viejo corriendo durante una hora. Bajarlo a 120 s.

#### 3.2 Reintentos con backoff exponencial

**Resuelve:** que un fallo transitorio de red pierda un fichaje para siempre. Es la causa de los `HTTP request failed` en la tabla de fallos.

```php
class SyncAttendanceToFactorial implements ShouldQueue
{
    public int $tries      = 3;
    public int $timeout    = 45;
    public array $backoff  = [10, 60, 300];   // 10s, 1min, 5min

    // No reintentar errores de negocio: solo los transitorios
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(30);
    }
}
```

**Clave para no empeorar las cosas:** hay que distinguir el fallo transitorio del definitivo. Un `Ya existe un turno en curso` **no debe reintentarse** (fallará igual 3 veces y gastará 3 llamadas a la API). Un `HTTP request failed` o un `429` **sí**.

```php
// Dentro del job, en el catch:
if ($e instanceof ConnectionException || $e->getCode() === 429 || $e->getCode() >= 500) {
    throw $e;               // deja que la cola reintente
}
$log->update(['sync_status' => 'failed', 'sync_error' => $mensaje]);
$this->fail($e);            // error de negocio: no reintentar
```

> ⚠️ **Cuidado con la idempotencia.** El `updateShift()` de sobreescritura marca el turno con `Editado por biométrico SFT`. Si un reintento ocurre después de que la primera llamada *sí* llegara a Factorial pero se perdiera la respuesta, hay riesgo de doble edición. El job **ya** consulta `findOpenShift()` antes de actuar, lo que cubre la mayoría de casos — pero **verificar este camino explícitamente con un test antes de activar los reintentos.** Esto es lo que hace que este cambio no sea trivial.

#### 3.3 Retardo por empleado, no global

**Resuelve:** el cuello de botella de fondo. Es el cambio de mayor impacto en throughput.

```php
// En lugar de un $delay global acumulativo:
$delayPorEmpleado = [];

foreach ($insertedIds as $logId) {
    $log  = $logsPorId[$logId];
    $emp  = $log->factorial_employee_id;

    $delay = $delayPorEmpleado[$emp] ?? 0;
    SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
    $delayPorEmpleado[$emp] = $delay + 2;   // el contador es POR empleado
}
```

**Por qué funciona:** el orden cronológico solo importa dentro del historial de un mismo empleado (que su `check_out` de ayer se registre antes que su `check_in` de hoy). Entre empleados distintos el orden es irrelevante para Factorial.

**Efecto medido sobre el escenario del §4.2:** un lote de 400 registros de 50 empleados distintos pasa de **13,3 minutos** de retardo programado a **~16 segundos** (8 registros por empleado × 2 s). Con 2 workers en la cola `attendance`, el drenado real ronda los **3–4 minutos** en lugar de 13.

Complemento recomendado: `WithoutOverlapping` por empleado, que hace la garantía de orden explícita en lugar de depender del retardo:

```php
public function middleware(): array
{
    return [
        (new WithoutOverlapping("factorial-emp:{$this->empleadoId}"))
            ->releaseAfter(5)
            ->expireAfter(120),
    ];
}
```

#### 3.4 Endurecer el cliente HTTP de Factorial

**Resuelve:** que un worker se quede colgado 30 s bloqueando la cola.

```php
// FactorialService::doRequest()
$request = Http::withToken($token)
    ->acceptJson()
    ->connectTimeout(5)      // ← no existe hoy: una conexión que no abre cuelga el worker
    ->timeout(20)            // bajar de 30 a 20
    ->retry(2, 500, function ($exception, $request) {
        // reintento inmediato SOLO para fallos de conexión
        return $exception instanceof ConnectionException;
    }, throw: false);
```

Y **manejar el 429** explícitamente, que hoy no se contempla en absoluto:

```php
if ($response->status() === 429) {
    $espera = (int) ($response->header('Retry-After') ?: 60);
    Log::warning('Factorial rate limit alcanzado', [
        'connection_id' => $this->connection->id,
        'retry_after'   => $espera,
    ]);
    throw new FactorialRateLimitedException($espera);
}
```

Y en el job, respetar esa espera:

```php
catch (FactorialRateLimitedException $e) {
    $this->release($e->retryAfter);   // devuelve el job a la cola sin gastar un intento
}
```

#### 3.5 Rate limiting *saliente* hacia Factorial

**Resuelve:** el escenario de "flujo constante de información a Factorial". Si con más workers empezamos a empujar más rápido, el siguiente muro es el límite de la API de Factorial — y hoy no hay ninguna protección contra eso.

```php
// AppServiceProvider::boot()
RateLimiter::for('factorial-api', fn () => Limit::perMinute(60));
```

```php
// En el job
public function middleware(): array
{
    return [
        new RateLimited('factorial-api'),
        (new WithoutOverlapping("factorial-emp:{$this->empleadoId}"))->releaseAfter(5),
    ];
}
```

> El límite real de la API de Factorial **no está documentado en el proyecto**. 60/min es una estimación conservadora de partida. Conviene confirmarlo con el contacto de soporte de Factorial (el mismo canal por el que se llevó el asunto de `toggle_clock`) y ajustar el número con dato real, no con suposición.

#### 3.6 Circuit breaker por conexión

**Resuelve:** que una caída de Factorial haga fallar en cascada cientos de jobs y llene `failed_jobs`.

```php
// Al detectar N fallos consecutivos de red en una conexión:
Cache::put("factorial:circuito_abierto:{$connectionId}", true, now()->addMinutes(5));

// Al inicio del job:
if (Cache::has("factorial:circuito_abierto:{$this->connectionId}")) {
    $this->release(120);   // reencolar sin consumir intento
    return;
}
```

Con esto, una caída de 10 minutos de Factorial hace que los jobs esperen en cola en lugar de quemarse contra un servicio caído.

#### 3.7 Resolver el atasco de `pending` acumulado

Hay **1.278 registros `pending`**, el más antiguo del **2 de junio**. El comando `attendance:resolve-pending` existe pero **no está en el scheduler** — `routes/console.php` solo tiene `close-forgotten-shifts`.

```php
// routes/console.php
Schedule::command('attendance:resolve-pending')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground();
```

> Antes de programarlo: **revisar por qué esos 1.278 llevan hasta tres meses sin resolver.** Si es porque el empleado nunca existirá en Factorial (baja, PIN de prueba, equipo mal mapeado), ejecutar el comando cada 30 minutos solo va a reintentar en bucle algo que nunca va a resolverse. Conviene primero clasificar esos 1.278 y añadir al comando un descarte automático de registros con más de N días sin mapeo posible.

---

### FASE 4 — Higiene operativa

#### 4.1 Política de retención de datos

`device_inventory_users` (271.561 filas, 94,8 MB, +30.000/día) es un histórico de inventario que crece sin límite.

```php
// routes/console.php
Schedule::call(function () {
    DB::table('device_inventory_users')->where('created_at', '<', now()->subDays(60))->delete();
    DB::table('device_commands')->where('created_at', '<', now()->subDays(90))->delete();
    DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(30))->delete();
    DB::table('sessions')->where('last_activity', '<', now()->subDays(30)->timestamp)->delete();
})->dailyAt('03:30')->name('purga-historicos');
```

> ⚠️ **Confirmar antes** que `device_inventory_users` es efectivamente un histórico desechable y no la fuente de verdad de alguna pantalla o de `DeviceAssignmentVerificationService`. Si alguna consulta depende de datos antiguos, la purga rompe funcionalidad. Verificar con `grep -rn "device_inventory_users" app/` antes de programar nada.

> ⚠️ Ejecutar la primera purga **manualmente y por lotes** (`->limit(5000)` en bucle). Un `DELETE` de 200.000 filas de golpe bloquea la tabla y puede tumbar el endpoint de inventario.

#### 4.2 Backup automatizado y fuera del servidor

Hoy hay un único volcado manual, en el mismo disco que se quiere proteger.

```bash
#!/bin/bash
# /usr/local/bin/backup-sintelcft.sh
set -euo pipefail
FECHA=$(date +%F-%H%M)
DEST=/var/backups/sintelcft
mkdir -p "$DEST"
mysqldump --single-transaction --quick --routines sintelcft \
  | gzip > "$DEST/db-$FECHA.sql.gz"
aws s3 cp "$DEST/db-$FECHA.sql.gz" "s3://sintelcft-backups/db/" --storage-class STANDARD_IA
find "$DEST" -name 'db-*.sql.gz' -mtime +7 -delete
```

Cron diario a las 03:00. Requiere un bucket S3 y un rol IAM en la instancia con permiso de escritura **solo** sobre ese prefijo.

**Además:** activar **snapshots automáticos de EBS** desde AWS (AWS Backup o Data Lifecycle Manager). Es la red de seguridad que cubre el caso de perder la instancia entera, y no depende de que ningún script funcione.

> Un backup nunca probado no es un backup. Programar una **restauración de prueba** a una base de datos temporal una vez al mes.

#### 4.3 Afinar PHP-FPM al perfil real de carga

El tráfico es constante (polling 24/7), no en ráfagas. `pm = dynamic` gasta ciclos creando y destruyendo hijos para nada.

```ini
; /etc/php/8.2/fpm/pool.d/www.conf
pm = static
pm.max_children = 10
pm.max_requests = 1000

; Visibilidad de peticiones lentas (hoy no existe)
slowlog = /var/log/php8.2-fpm-slow.log
request_slowlog_timeout = 5s
request_terminate_timeout = 60s
```

Y en `php.ini` de FPM, para reducir el coste de arranque de los 33.570 polls diarios:

```ini
opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0     ; ← requiere reiniciar FPM en CADA deploy
opcache.interned_strings_buffer = 16
```

> ⚠️ `opcache.validate_timestamps = 0` es la optimización de mayor impacto para este perfil de carga, **pero si se activa hay que añadir `systemctl reload php8.2-fpm` al procedimiento de despliegue.** Si se olvida, el código nuevo no se carga y el síntoma (la aplicación sigue con el comportamiento viejo tras el deploy) es desconcertante. Añadirlo a la sección de despliegue del `CLAUDE.md` en el mismo cambio.

**Cálculo:** 10 hijos × 52 MB = 520 MB, más 3 workers (~576 MB) más MySQL (547 MB) ≈ **1,64 GB**. Cabe.

#### 4.4 Reducir el coste del endpoint de polling

90,7% del tráfico es `/iclock/getrequest`, que en la mayoría de casos devuelve una respuesta vacía tras arrancar todo Laravel.

Opciones, de menos a más invasiva:

1. **Caché del "no hay comandos"** — mantener en caché por `SN` un flag de "sin comandos pendientes", invalidado al encolar uno. Evita la consulta a `device_commands` en la gran mayoría de polls. Barato y sin riesgo.
2. **Aumentar el intervalo de polling en los equipos** — si el firmware lo permite, pasar de ~1,8/min a 1/min reduce el tráfico a la mitad sin tocar código. Comprobar el impacto en la latencia de entrega de comandos.
3. **Ruta ligera fuera del stack completo** — solo si 1 y 2 no bastan. Complejidad alta, beneficio marginal con la carga actual. **No recomendado ahora.**

#### 4.5 Observabilidad y alertas

Hoy no hay ninguna alerta. Los 25 errores 500 de `/iclock/getrequest` de hoy no dispararon nada, y el incidente de UTF-8 se descubrió por sus efectos, no por un aviso.

Métricas mínimas a vigilar, con umbral:

| Métrica | Umbral de alerta |
|---|---|
| Profundidad de la cola (`jobs`) | > 200 pendientes durante 5 min |
| Job más antiguo en cola | > 10 minutos |
| `failed_jobs` nuevos | > 10 en una hora |
| Tasa de `attendance_logs.failed` | > 10% del día |
| Dispositivo sin `getrequest` | > 30 min sin poll → probable caída |
| Disco | > 85% |
| Errores 5xx en nginx | > 20/hora |

Implementación mínima viable, sin dependencias externas: un comando `artisan flowtime:health` programado cada 15 minutos que evalúe estos umbrales y envíe correo. Es infinitamente mejor que nada, que es lo que hay ahora.

> El punto de "dispositivo sin poll > 30 min" es especialmente valioso: es exactamente el síntoma que tuvo el incidente de OPERLOG/UTF-8 (equipos que se veían "En línea" pero habían dejado de subir asistencia).

#### 4.6 Limpieza del repositorio

`git status` muestra **30 ficheros sin commitear**, incluidos `app/Jobs_old/` y `app/Services_old/` — directorios duplicados de código ya identificados en la auditoría de arquitectura del 24 de agosto. Código muerto que se carga en el autoloader de Composer y confunde cualquier búsqueda. Eliminarlos y confirmar el resto del árbol de trabajo.

---

## 6. Plan de aplicación sugerido

Ordenado para que cada paso sea reversible y verificable antes del siguiente.

### Bloque A — Sin riesgo, hoy mismo

- [ ] `LOG_LEVEL=warning` + degradar `Log::info` de polling a `debug` (§1.1)
- [ ] Retención de 14 días en el canal de logs (§1.2)
- [ ] `chmod 640 .env`, eliminar `.env.bak` (§1.3)
- [ ] `ssl_protocols TLSv1.2 TLSv1.3` + `server_tokens off` (§2.5)
- [ ] `client_max_body_size 2m` (§2.3)
- [ ] Eliminar `Jobs_old/` y `Services_old/` (§4.6)

**Verificación:** `du -sh storage/logs` al día siguiente debería estar por debajo de 5 MB. Los equipos siguen fichando con normalidad.

### Bloque B — Endpoints (requiere ventana y prueba con un equipo)

- [ ] Middleware de validación de SN activo (§2.1, sin el secreto compartido todavía)
- [ ] `RateLimiter::for('iclock')` a 120/min por SN (§2.2)
- [ ] `limit_req` en nginx con `set_real_ip_from` de Cloudflare (§2.3 + §2.4b)
- [ ] Security Group: puertos 80/8080 solo desde rangos de Cloudflare (§2.4a)
- [ ] `trustProxies` con lista explícita (§2.4c)
- [ ] fail2ban, **después** de que `real_ip` funcione (§2.6)

**Verificación:** en los logs, la IP de cliente deja de ser de Cloudflare y pasa a ser la real. Ningún equipo deja de hacer polling. Cero rechazos por rate limit durante una semana.

### Bloque C — Canal a Factorial (el bloque de mayor impacto, y el que más pruebas requiere)

- [ ] Separar colas `attendance` / `bulk` + 2 workers en `attendance` (§3.1)
- [ ] `connectTimeout(5)`, `timeout(20)`, manejo de 429 (§3.4)
- [ ] Retardo por empleado en lugar de global (§3.3)
- [ ] `WithoutOverlapping` por empleado (§3.3)
- [ ] Reintentos con backoff **y clasificación transitorio/definitivo** (§3.2) — el paso que más test necesita
- [ ] `RateLimited('factorial-api')` (§3.5)
- [ ] Circuit breaker por conexión (§3.6)
- [ ] Programar `attendance:resolve-pending`, tras clasificar los 1.278 atascados (§3.7)

**Verificación:** simular un lote de 200 registros con un equipo de pruebas y medir el tiempo de drenado antes y después. La tasa de `failed` diaria debería bajar del 27% actual — no a cero, porque la mayoría son conflictos legítimos de negocio, pero sí deberían desaparecer los `HTTP request failed`.

### Bloque D — Higiene

- [ ] Slow query log de MySQL (§1.4)
- [ ] Backup automatizado a S3 + snapshots EBS (§4.2)
- [ ] Purga de históricos, previa verificación de dependencias (§4.1)
- [ ] PHP-FPM `static` + OPcache, **con reload en el deploy** (§4.3)
- [ ] Caché del "sin comandos pendientes" (§4.4)
- [ ] Comando `flowtime:health` con alertas (§4.5)

---

## 7. Lo que este documento **no** propone

Por transparencia sobre los límites del análisis:

- **No propone migrar a Redis.** Sería la mejora natural para cola y caché, pero en un `t3.micro` con 3,7 GB donde ya conviven MySQL, PHP-FPM y los workers, añadir Redis consume memoria que no sobra. Con el volumen actual (menos de 500 fichajes/día), MySQL como driver de cola no es el cuello de botella — el diseño de serialización sí. **Reconsiderar si se pasa a una instancia mayor o si el volumen se multiplica por diez.**

- **No propone escalar horizontalmente.** Con load 0.64 sobre 2 vCPU no está justificado. La ganancia está en el diseño, no en más hierro.

- **No toca `toggle_clock`.** Está descartado por decisión documentada tras dos intentos fallidos en producción.

- **No resuelve el hueco de `edit_timesheet_requests`.** Sigue pendiente de conocer el valor correcto del enum `request_type`, que no se puede adivinar contra la API de un cliente real.

- **Los umbrales concretos son puntos de partida, no verdades.** 120 req/min por dispositivo, 60 llamadas/min a Factorial, 60 días de retención de inventario: todos son estimaciones conservadoras basadas en el comportamiento medido hoy. Hay que ajustarlos con datos reales tras aplicarlos, no darlos por buenos.

---

## 8. Anexo: comandos de verificación

```bash
# Estado de la cola en vivo
php artisan queue:monitor attendance:100,bulk:200

# Profundidad de cola y job más antiguo
php artisan tinker --execute='
  echo "pendientes: ".DB::table("jobs")->count()."\n";
  $o=DB::table("jobs")->orderBy("available_at")->first();
  echo "más antiguo: ".($o?date("Y-m-d H:i:s",$o->available_at):"n/a")."\n";'

# Tasa de fallo del día
php artisan tinker --execute='
  $t=DB::table("attendance_logs")->whereDate("created_at",today())->count();
  $f=DB::table("attendance_logs")->whereDate("created_at",today())->where("sync_status","failed")->count();
  printf("fallos: %d/%d (%.1f%%)\n",$f,$t,$t?100*$f/$t:0);'

# Dispositivos que llevan rato sin hacer polling
awk '{print $7}' /var/log/nginx/access.log | grep -oP 'SN=\K[A-Z0-9]+' | sort -u > /tmp/sn_hoy.txt
tail -2000 /var/log/nginx/access.log | grep -oP 'SN=\K[A-Z0-9]+' | sort -u > /tmp/sn_reciente.txt
comm -23 /tmp/sn_hoy.txt /tmp/sn_reciente.txt   # aparecen hoy pero no recientemente

# Peso del log de hoy
du -sh /var/www/sintelcft/storage/logs/laravel-$(date +%F).log

# Errores 5xx de la última hora
awk -v h="$(date -d '1 hour ago' '+%d/%b/%Y:%H')" '$0 ~ h && $9 ~ /^5/' /var/log/nginx/access.log | wc -l

# Presión de memoria
free -h && ps -eo pmem,rss,comm --sort=-rss | head -12
```
