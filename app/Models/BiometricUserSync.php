<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricUserSync extends Model
{
    protected $fillable = [
        'client_id',
        'biometric_provider_id',
        'factorial_employee_id',
        'external_employee_code',
        'local_name',
        'provider_user_id',
        'sync_status',
        'sync_error',
        'status',
        'archived_at',
        'last_attempt_at',
        'synced_at',
        'raw_request',
        'raw_response',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
        'synced_at' => 'datetime',
        'archived_at' => 'datetime',
        'raw_request' => 'array',
        'raw_response' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BiometricProvider::class);
    }

    public function factorialEmployee(): BelongsTo
    {
        return $this->belongsTo(FactorialEmployee::class);
    }

    public function deviceAssignments(): HasMany
    {
        return $this->hasMany(DeviceUserAssignment::class);
    }
}
