<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;

class PlatformSettingsService
{
    private const DEFAULTS = [
        'general' => [
            'platform_name' => 'TastyBite',
            'support_email' => '',
            'support_phone' => '',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'INR',
            'date_format' => 'M j, Y',
        ],
        'subscription' => [
            'expiry_reminder_days' => [7, 3, 1],
            'expiry_alerts_enabled' => true,
        ],
        'payment' => [
            'manual_enabled' => true,
            'currency' => 'INR',
            'allowed_methods' => ['cash', 'bank_transfer', 'upi', 'other'],
            'instructions' => '',
            'proof_required' => false,
        ],
        'notifications' => [
            'enabled' => true,
            'expiry_alerts' => true,
            'payment_alerts' => true,
            'restaurant_alerts' => true,
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $defaults = self::DEFAULTS[$group] ?? [];
        $stored = PlatformSetting::query()->where('group', $group)->get(['key', 'value']);

        foreach ($stored as $setting) {
            $decoded = json_decode($setting->value, true);
            $prefix = $group.'.';
            if (json_last_error() === JSON_ERROR_NONE && str_starts_with($setting->key, $prefix)) {
                $defaults[substr($setting->key, strlen($prefix))] = $decoded;
            }
        }

        return $defaults;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return array_combine(
            array_keys(self::DEFAULTS),
            array_map(fn (string $group): array => $this->group($group), array_keys(self::DEFAULTS)),
        );
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function saveGroup(string $group, array $values, SuperAdmin $actor): void
    {
        if (! array_key_exists($group, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown platform settings group.');
        }

        $before = $this->group($group);

        DB::transaction(function () use ($group, $values, $actor, $before): void {
            foreach ($values as $key => $value) {
                PlatformSetting::query()->updateOrCreate(
                    ['key' => $group.'.'.$key],
                    ['group' => $group, 'value' => json_encode($value, JSON_THROW_ON_ERROR)],
                );
            }

            RestaurantAuditLog::create([
                'actor_id' => $actor->id,
                'actor_type' => 'super_admin',
                'action' => 'platform_settings_updated',
                'target_type' => 'platform_settings',
                'target_id' => null,
                'details' => [
                    'group' => $group,
                    'before' => $this->redact($before),
                    'after' => $this->redact(array_replace($before, $values)),
                ],
            ]);
        });
    }

    public function applyRuntime(): void
    {
        $general = $this->group('general');
        config([
            'app.name' => $general['platform_name'],
            'app.timezone' => $general['timezone'],
            'app.date_format' => $general['date_format'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token|credential|api.?key/i', (string) $key)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
