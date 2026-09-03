<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_attendance_configs', function (Blueprint $table) {
            // El cliente solo registra entrada (nunca manda check_out). Es una
            // etiqueta, no un interruptor de comportamiento: el mecanismo de
            // cierre automático (attendance:close-forgotten-shifts) es el mismo
            // para todos — esto solo decide si el resultado es rutina esperada
            // (true) o una excepción a marcar para revisión (false).
            $table->boolean('checkin_only')->default(false)->after('client_id');

            // Activa el cierre automático de turnos abiertos para este cliente.
            // Se fuerza a true cuando checkin_only = true (si solo registra
            // entrada, el cierre automático no es opcional). Para clientes
            // normales es un opt-in: el "turno olvidado" (empleado que no
            // marcó salida) es una excepción, no todos los clientes lo quieren
            // resuelto automáticamente sin revisarlo primero.
            $table->boolean('auto_close_forgotten_shifts')->default(false)->after('checkin_only');
        });
    }

    public function down(): void
    {
        Schema::table('client_attendance_configs', function (Blueprint $table) {
            $table->dropColumn(['checkin_only', 'auto_close_forgotten_shifts']);
        });
    }
};
