<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ventana anti-rebote (segundos)
    |--------------------------------------------------------------------------
    |
    | Los equipos multimodales (huella + cara) reportan la MISMA llegada varias
    | veces con segundos de diferencia. Cada repetición llegaba a Factorial como
    | un check_in nuevo, chocaba con "open_shift" (409) y el fallback de
    | FactorialService::updateShift() terminaba sobrescribiendo el clock_in real
    | con la lectura más tardía — corriendo la hora de entrada hacia adelante.
    |
    | Caso testigo (OUTLANDISH, equipo UEED244300146, 2026-09-01):
    |   13:52:58 v=1  → turno creado con la hora correcta
    |   13:53:03 v=1  → overwrite: el clock_in pasó a 13:53:03
    |   13:53:08 v=15 → rebotado como "Turno ya editado"
    | El cierre automático heredó el error (13:53:03 + 8h = 21:53:03).
    |
    | Dentro de la ventana gana SIEMPRE la primera lectura; las repeticiones se
    | guardan con sync_status 'descartado' — siguen visibles en la pantalla de
    | registros del cliente, pero nunca se despachan a Factorial.
    |
    | 0 desactiva el filtro por completo.
    |
    */

    'dedupe_window_seconds' => (int) env('ATTENDANCE_DEDUPE_WINDOW', 120),

    /*
    |--------------------------------------------------------------------------
    | Override por cliente
    |--------------------------------------------------------------------------
    |
    | [client_id => segundos]. Útil si algún cliente tiene un flujo legítimo de
    | ponchadas muy seguidas y necesita una ventana más corta (o 0).
    |
    */

    'dedupe_window_overrides' => [],

    /*
    |--------------------------------------------------------------------------
    | Una lectura por persona, tipo y día — salvo que haya una salida en medio
    |--------------------------------------------------------------------------
    |
    | [client_id, ...]. OPT-IN por cliente.
    |
    | Complementa la ventana anti-rebote, que solo cubre repeticiones separadas
    | por SEGUNDOS. Aquí se cubren las separadas por HORAS: la persona pasa
    | varias veces al día por el lector y cada pasada viaja a Factorial como una
    | orden nueva. O choca —«Ya existe un turno»— o, peor, entra por el fallback
    | de updateShift() y PISA el clock_in real con la lectura más tardía.
    |
    | La regla NO es «una entrada por día». Una lectura repetida se descarta
    | solo si no hay una lectura del tipo OPUESTO entre ella y la anterior del
    | mismo tipo: quien sale a comer y vuelve conserva su segunda entrada.
    |
    | ── Por qué está activada en estos dos y no en el resto ──
    |
    | Medido sobre 14 días de datos reales el 2026-09-04, antes de activar:
    |
    |   OUTLANDISH (#4)   691 jornadas · 271 con entrada repetida ·  0 con salida
    |                     en medio. Es checkin_only: el equipo nunca manda
    |                     salidas, así que no existe una segunda entrada
    |                     legítima. De las repeticiones, 339 fallaban a la vista
    |                     y 294 figuraban como «synced» habiendo sobrescrito el
    |                     turno — el daño que no se veía. Caso PIN 2003002,
    |                     2026-08-27: entró 04:26, la pasada de 11:20 movió su
    |                     clock_in, y el cierre automático heredó la hora mala.
    |                     Su jornada quedó registrada como 11:20→22:20.
    |
    |   ACERMEX (#21)     180 jornadas · 131 con entrada repetida · 58 CON salida
    |                     en medio. Esas 58 son regresos reales, y por eso este
    |                     cliente necesitaba primero el guardarraíl. Con él se
    |                     conservan, y se descartan las 73 que sí son
    |                     repeticiones.
    |
    | ── Antes de añadir un tercero ──
    |
    | Hay que repetir la medición, no suponerla. Lo que la hace segura es
    | comparar, por cliente, cuántas jornadas con lectura repetida tienen una
    | lectura opuesta en medio. Si esa proporción es alta y el equipo registra
    | mal las salidas, activarlo pierde datos reales.
    |
    | Un cliente con turnos que cruzan medianoche NO debe activarla: el día
    | laboral aquí es el día natural de America/Mexico_City, sin hora de corte.
    |
    */

    'once_per_day_clients' => [
        4,   // OUTLANDISH SA DE CV
        21,  // ACERMEX
    ],

];
