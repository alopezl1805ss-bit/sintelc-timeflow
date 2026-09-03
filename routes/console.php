<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El mapeo de empleados es ahora manual desde la UI — sin scheduler automático
// para eso. El cierre de turnos olvidados sí necesita correr solo: si se deja
// manual, un turno abierto bloquea el siguiente check_in del empleado con
// "open_shift" hasta que alguien note el problema y corra el comando a mano.
Schedule::command('attendance:close-forgotten-shifts')->hourly();
