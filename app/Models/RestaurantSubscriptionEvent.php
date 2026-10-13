<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSubscriptionEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('Subscription history events are immutable.');
        });
        static::deleting(static function (): void {
            throw new \LogicException('Subscription history events cannot be deleted.');
        });
    }

    protected $fillable = [
        'restaurant_id',
        'restaurant_subscription_id',
        'subscription_plan_id',
        'actor_id',
        'action',
        'previous_status',
        'new_status',
        'starts_at',
        'expires_at',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'details' => 'array',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(RestaurantSubscription::class, 'restaurant_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'actor_id');
    }
}
