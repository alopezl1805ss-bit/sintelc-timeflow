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
    | Una lectura por persona, tipo y día
    |--------------------------------------------------------------------------
    |
    | [client_id, ...]. OPT-IN, deliberadamente vacío.
    |
    | Complementa la ventana anti-rebote, que solo cubre repeticiones separadas
    | por segundos. Aquí se cubren las separadas por HORAS: la persona pasa
    | varias veces al día por el lector y cada pasada viaja a Factorial como una
    | orden nueva, chocando con «Ya existe un turno» o «Turno ya editado».
    |
    | Medido sobre los datos reales del 2026-09-04: activarlo habría evitado el
    | 75 % de los fallos de los últimos días.
    |
    | NO lo actives para un cliente que tenga un flujo legítimo de salir y
    | volver el mismo día: la segunda entrada sería real y se perdería. Candidatos
    | claros según los datos: ACERMEX (36 de los 51 fallos recientes) y
    | OUTLANDISH (checkin_only: por definición nunca hay una segunda entrada
    | legítima).
    |
    */

    'once_per_day_clients' => [],

];
