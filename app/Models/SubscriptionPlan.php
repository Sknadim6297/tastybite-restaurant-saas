<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'price',
        'currency',
        'billing_period',
        'duration_months',
        'duration_days',
        'trial_available',
        'trial_duration_days',
        'status',
    ];

    protected static function booted(): void
    {
        static::updating(function (SubscriptionPlan $plan): void {
            if ($plan->isDirty('code')) {
                throw new LogicException('Subscription plan codes are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_months' => 'integer',
            'duration_days' => 'integer',
            'trial_available' => 'boolean',
            'trial_duration_days' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(RestaurantSubscription::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(SaasFeature::class, 'subscription_plan_features')
            ->withPivot(['is_enabled', 'limit_value'])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
