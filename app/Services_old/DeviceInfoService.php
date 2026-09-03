<?php

namespace App\Services;

use App\Models\BiometricSource;

class DeviceInfoService
{
    public function __construct(
        private readonly DeviceProtocolResolver $protocols,
        private readonly DeviceAggregateVerificationService $verification,
    ) {}

    public function capture(BiometricSource $source, string $rawInfo): void
    {
        $parts = array_map('trim', explode(',', $rawInfo));
        if ($parts === [] || $parts[0] === '') {
            return;
        }

        $reportedAt = now();
        $firmware = mb_substr($parts[0], 0, 255);
        $profile = $this->protocols->resolve($source, $firmware);
        $integer = fn(int $index): ?int => isset($parts[$index]) && preg_match('/^\d+$/', $parts[$index])
            ? (int) $parts[$index]
            : null;

        $source->update([
            'device_firmware' => $firmware,
            'reported_user_count' => $integer(1),
            'reported_fingerprint_count' => $integer(2),
            'reported_face_count' => $integer(3),
            'device_info_reported_at' => $reportedAt,
            'device_info_payload' => ['raw' => $rawInfo, 'parts' => $parts],
            'push_protocol_profile' => $profile,
            'push_protocol_source' => $source->push_protocol_source === 'manual' ? 'manual' : 'auto',
            'push_protocol_detected_at' => $reportedAt,
        ]);

        if ($integer(1) !== null) {
            $this->verification->verify($source->fresh(), $integer(1), $reportedAt);
        }
    }
}
