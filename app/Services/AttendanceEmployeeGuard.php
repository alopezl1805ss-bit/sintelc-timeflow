<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\FactorialEmployee;
use Illuminate\Support\Facades\Log;

/**
 * Guardarraíl de aislamiento entre empresas (incidencia H01).
 *
 * Un `factorial_employee_id` sólo puede escribirse o despacharse sobre un
 * `attendance_log` si el empleado pertenece al MISMO cliente que el log. El
 * cruce se verifica contra el modelo de datos —`factorial_employees.client_id`
 * y el `client_id` de su `factorial_connection`— y no contra el mapa en
 * memoria que construyó quien llama: el mismo cruce podría entrar por otra vía
 * (un `BiometricUserSync` mal grabado, una importación manual, un job antiguo)
 * y el efecto es nómina de una empresa dentro del Factorial de otra.
 *
 * Cualquier discrepancia es un incidente, no un caso borde: se registra como
 * `critical` y la operación NO se despacha.
 */
class AttendanceEmployeeGuard
{
    /** Memo por proceso: employeeId => [client_id dueños] */
    private static array $owners = [];

    /**
     * ¿El empleado pertenece a ese cliente?
     *
     * Un empleado inexistente devuelve false a propósito: sin fila no hay
     * manera de demostrar de quién es la nómina, y ante la duda no se toca.
     */
    public static function employeeBelongsToClient(int|string|null $employeeId, int|string|null $clientId): bool
    {
        if (empty($employeeId) || empty($clientId)) {
            return false;
        }

        $employeeId = (int) $employeeId;

        if (!array_key_exists($employeeId, self::$owners)) {
            $row = FactorialEmployee::query()
                ->leftJoin('factorial_connections', 'factorial_connections.id', '=', 'factorial_employees.factorial_connection_id')
                ->where('factorial_employees.id', $employeeId)
                ->first([
                    'factorial_employees.client_id as employee_client_id',
                    'factorial_connections.client_id as connection_client_id',
                ]);

            self::$owners[$employeeId] = $row
                ? array_values(array_unique(array_filter([
                    $row->employee_client_id !== null ? (int) $row->employee_client_id : null,
                    $row->connection_client_id !== null ? (int) $row->connection_client_id : null,
                ], fn ($v) => $v !== null)))
                : [];
        }

        return in_array((int) $clientId, self::$owners[$employeeId], true);
    }

    /**
     * Verificación previa al despacho. Devuelve false (y registra crítico) si
     * el empleado asignado al log no es de la empresa del log.
     *
     * @param  AttendanceLog|object  $log  necesita id, client_id y factorial_employee_id
     */
    public static function canDispatch(object $log, string $origin): bool
    {
        if (self::employeeBelongsToClient($log->factorial_employee_id ?? null, $log->client_id ?? null)) {
            return true;
        }

        Log::critical('H01 — nómina cruzada entre empresas: empleado ajeno al cliente del registro, no se despacha', [
            'origen'                => $origin,
            'attendance_log_id'     => $log->id ?? null,
            'log_client_id'         => $log->client_id ?? null,
            'factorial_employee_id' => $log->factorial_employee_id ?? null,
            'employee_client_ids'   => self::$owners[(int) ($log->factorial_employee_id ?? 0)] ?? [],
        ]);

        return false;
    }

    /** Sólo para pruebas: limpia el memo. */
    public static function flush(): void
    {
        self::$owners = [];
    }
}
