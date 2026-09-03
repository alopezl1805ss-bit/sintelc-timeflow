<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\BiometricProvider;
use App\Models\BiometricSource;
use App\Models\BiometricUserSync;
use App\Models\FactorialEmployee;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessCsvAutoMatch implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 300;

    public function __construct(
        public readonly int $biometricSourceId
    ) {}

    public function handle(): void
    {
        $source = BiometricSource::find($this->biometricSourceId);

        if (!$source || empty($source->device_users)) {
            Log::warning('ProcessCsvAutoMatch: dispositivo no encontrado o sin usuarios', [
                'biometric_source_id' => $this->biometricSourceId,
            ]);
            return;
        }

        $provider  = BiometricProvider::where('client_id', $source->client_id)->first();
        $employees = FactorialEmployee::where('client_id', $source->client_id)->get();

        $existing = BiometricUserSync::where('client_id', $source->client_id)
            ->whereNotNull('factorial_employee_id')
            ->pluck('factorial_employee_id', 'external_employee_code');

        $normalize = fn($s) => preg_replace('/\s+/', ' ', trim(str_replace(
            ['„','ê','û','î','â','ô','Ñ','ñ','Á','á','É','é','Í','í','Ó','ó','Ú','ú','Ü','ü'],
            ['n','e','u','i','a','o','n','n','a','a','e','e','i','i','o','o','u','u','u','u'],
            mb_strtolower($s)
        )));

        $autoMapped = 0;
        $pending    = 0;
        $now        = now();
        $delay      = 0;

        foreach ($source->device_users as $user) {
            $pin = $user['pin'];

            if (isset($existing[$pin])) continue;

            $normPin   = $normalize($user['name']);
            $best      = 0;
            $bestEmpId = null;

            foreach ($employees as $emp) {
                similar_text($normPin, $normalize($emp->full_name), $pct);
                if ($pct > $best) {
                    $best      = $pct;
                    $bestEmpId = $emp->id;
                }
            }

            if ($best >= 95 && $bestEmpId && $provider) {
                BiometricUserSync::updateOrCreate(
                    ['biometric_provider_id' => $provider->id, 'factorial_employee_id' => $bestEmpId],
                    [
                        'client_id'              => $source->client_id,
                        'external_employee_code' => $pin,
                        'sync_status'            => 'pending',
                        'last_attempt_at'        => $now,
                    ]
                );

                $logIds = AttendanceLog::where('client_id', $source->client_id)
                    ->where('employee_code', $pin)
                    ->whereNull('factorial_employee_id')
                    ->pluck('id');

                AttendanceLog::whereIn('id', $logIds)->update([
                    'factorial_employee_id' => $bestEmpId,
                    'sync_status'           => 'resolved',
                ]);

                foreach ($logIds as $logId) {
                    SyncAttendanceToFactorial::dispatch($logId)->delay(now()->addSeconds($delay));
                    $delay += 2;
                }

                $autoMapped++;
            } else {
                $pending++;
            }
        }

        Log::info('ProcessCsvAutoMatch: completado', [
            'biometric_source_id' => $this->biometricSourceId,
            'auto_mapped'         => $autoMapped,
            'pending'             => $pending,
        ]);
    }
}
