<?php

namespace App\Services;

use App\Models\RestaurantAuditLog;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeatureManagementService
{
    /**
     * @param  array<int, array{enabled: bool, limit: int|null}>  $planSettings
     */
    public function update(SaasFeature $feature, bool $enabled, array $planSettings, SuperAdmin $actor): void
    {
        if (! $feature->implemented) {
            throw ValidationException::withMessages([
                'enabled' => 'This feature is not implemented and cannot be enabled.',
            ]);
        }

        DB::transaction(function () use ($feature, $enabled, $planSettings, $actor): void {
            $lockedFeature = SaasFeature::query()->lockForUpdate()->findOrFail($feature->id);
            if (! $lockedFeature->implemented) {
                throw ValidationException::withMessages([
                    'enabled' => 'This feature is not implemented and cannot be enabled.',
                ]);
            }

            $plans = SubscriptionPlan::query()
                ->whereKey(array_keys($planSettings))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($plans->count() !== count($planSettings)) {
                throw ValidationException::withMessages([
                    'plans' => 'One or more selected subscription plans no longer exist.',
                ]);
            }

            $before = [
                'enabled' => $lockedFeature->is_enabled,
                'plans' => [],
            ];
            foreach ($planSettings as $planId => $settings) {
                $existing = DB::table('subscription_plan_features')
                    ->where('subscription_plan_id', $planId)
                    ->where('saas_feature_id', $lockedFeature->id)
                    ->first();
                $before['plans'][$planId] = [
                    'enabled' => (bool) ($existing->is_enabled ?? false),
                    'limit' => $existing->limit_value ?? null,
                    'created_at' => $existing->created_at ?? null,
                ];
            }

            $lockedFeature->update(['is_enabled' => $enabled]);

            foreach ($planSettings as $planId => $settings) {
                DB::table('subscription_plan_features')->updateOrInsert(
                    [
                        'subscription_plan_id' => $planId,
                        'saas_feature_id' => $lockedFeature->id,
                    ],
                    [
                        'is_enabled' => (bool) $settings['enabled'],
                        'limit_value' => $settings['limit'],
                        'updated_at' => now(),
                        'created_at' => $before['plans'][$planId]['created_at'] ?? now(),
                    ],
                );
            }

            RestaurantAuditLog::create([
                'actor_id' => $actor->id,
                'actor_type' => 'super_admin',
                'action' => 'saas_feature_access_updated',
                'target_type' => 'saas_feature',
                'target_id' => $lockedFeature->id,
                'details' => [
                    'feature_code' => $lockedFeature->code,
                    'before' => $before,
                    'after' => [
                        'enabled' => $enabled,
                        'plans' => $planSettings,
                    ],
                ],
            ]);
        });
    }
}
