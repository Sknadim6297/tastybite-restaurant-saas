<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantAuditLog extends Model
{
    protected static function booted(): void
    {
        static::creating(static function (self $log): void {
            if (! app()->runningInConsole() && blank($log->ip_address)) {
                $log->ip_address = request()->ip();
            }

            $restaurantId = data_get($log->details, 'restaurant_id');
            if (! $restaurantId && $log->target_type === 'restaurant') {
                $restaurantId = $log->target_id;
            }
            if (! $restaurantId && $log->target_type === 'restaurant_user' && $log->target_id) {
                $restaurantId = User::query()->whereKey($log->target_id)->value('restaurant_id');
            }
            if (! $restaurantId && $log->target_type === 'subscription_payment' && $log->target_id) {
                $restaurantId = SubscriptionPayment::query()->whereKey($log->target_id)->value('restaurant_id');
            }
            if ($restaurantId) {
                $log->restaurant_id = $restaurantId;
            }
        });
    }

    protected $fillable = [
        'actor_id',
        'actor_type',
        'restaurant_id',
        'ip_address',
        'action',
        'target_type',
        'target_id',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'actor_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
