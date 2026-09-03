<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado de ciclo de vida del empleado dentro de FlowTime — independiente
     * de FactorialEmployee.active (que refleja el estado en Factorial).
     * 'archived' es la baja general (Alcance 1.3/1.6): el empleado deja de
     * aparecer en las listas activas y se solicita su baja de todos los
     * dispositivos asociados, pero se conserva el registro y su histórico de
     * asistencia — nunca se hace hard-delete.
     */
    public function up(): void
    {
        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->string('status')->default('active')->after('sync_status');
            $table->timestamp('archived_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_user_syncs', function (Blueprint $table) {
            $table->dropColumn(['status', 'archived_at']);
        });
    }
};
