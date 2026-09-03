<?php

namespace App\Services;

use App\Models\BiometricSource;

class DeviceProtocolResolver
{
    public function resolve(BiometricSource $source, ?string $firmware = null): string
    {
        if ($source->push_protocol_source === 'manual' && $source->push_protocol_profile) {
            return $source->push_protocol_profile;
        }

        $deviceName = strtolower((string) $source->device_name);
        $firmware = strtolower($firmware ?? (string) $source->device_firmware);

        foreach (config('biometric-protocols.profiles', []) as $profile => $settings) {
            foreach ($settings['device_names'] ?? [] as $needle) {
                if ($needle !== '' && str_contains($deviceName, strtolower($needle))) {
                    return $profile;
                }
            }
            foreach ($settings['firmware_prefixes'] ?? [] as $prefix) {
                if ($prefix !== '' && str_starts_with($firmware, strtolower($prefix))) {
                    return $profile;
                }
            }
        }

        return config('biometric-protocols.default', 'attendance_push');
    }

    public function inventoryMode(BiometricSource $source): string
    {
        $profile = $this->resolve($source);
        return config("biometric-protocols.profiles.{$profile}.inventory_mode", 'detailed');
    }

    public function inventoryCommand(BiometricSource $source): string
    {
        $profile = $this->resolve($source);
        return config("biometric-protocols.profiles.{$profile}.inventory_command", 'DATA QUERY USERINFO');
    }
}
