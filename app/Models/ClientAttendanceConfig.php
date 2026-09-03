<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientAttendanceConfig extends Model
{
    protected $fillable = [
        'client_id',
        'checkin_only',
        'auto_close_forgotten_shifts',
        'checkin_id',
        'checkout_id',
        'has_breaks',
        'breakin_id',
        'breakout_id',
    ];

    protected $casts = [
        'has_breaks'                  => 'boolean',
        'checkin_only'                => 'boolean',
        'auto_close_forgotten_shifts' => 'boolean',
    ];

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function resolveCheckType(string $biometricStatusId): ?string
    {
        return match ($biometricStatusId) {
            $this->checkin_id  => 'check_in',
            $this->checkout_id => 'check_out',
            $this->breakin_id  => 'break_in',
            $this->breakout_id => 'break_out',
            default            => null,
        };
    }

    /**
     * Mapeo estándar ZKTeco cuando no hay ClientAttendanceConfig configurado.
     * 0/4 = entrada, 1/5 = salida, 2 = inicio descanso, 3 = fin descanso.
     */
    public static function defaultCheckType(?string $status): string
    {
        return match ($status) {
            '0', '4' => 'check_in',
            '1', '5' => 'check_out',
            '2'      => 'break_in',
            '3'      => 'break_out',
            default  => 'unknown',
        };
    }
}
