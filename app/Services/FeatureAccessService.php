<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;

class FeatureAccessService
{
    public function allows(Restaurant $restaurant, string $featureCode): bool
    {
        $now = now(config('app.timezone'))->utc();

        return DB::table('restaurant_subscriptions as subscriptions')
            ->join('subscription_plan_features as plan_features', 'plan_features.subscription_plan_id', '=', 'subscriptions.subscription_plan_id')
            ->join('saas_features as features', 'features.id', '=', 'plan_features.saas_feature_id')
            ->where('subscriptions.restaurant_id', $restaurant->id)
            ->whereIn('subscriptions.status', ['active', 'trial'])
            ->where('subscriptions.starts_at', '<=', $now)
            ->where('subscriptions.expires_at', '>=', $now)
            ->where('features.code', $featureCode)
            ->where('features.implemented', true)
            ->where('features.is_enabled', true)
            ->where('plan_features.is_enabled', true)
            ->exists();
    }
}
