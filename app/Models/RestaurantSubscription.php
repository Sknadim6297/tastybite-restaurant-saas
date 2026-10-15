<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantSubscription extends Model
{
    protected $fillable = [
        'restaurant_id',
        'subscription_plan_id',
        'plan_name',
        'plan_code',
        'price',
        'currency',
        'billing_period',
        'duration_months',
        'duration_days',
        'status',
        'is_trial',
        'starts_at',
        'expires_at',
        'created_by',
        'renewal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_months' => 'integer',
            'duration_days' => 'integer',
            'is_trial' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by');
    }

    public function renewalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewal_of_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RestaurantSubscriptionEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'restaurant_subscription_id');
    }

    public function getFormattedIdAttribute(): ?string
    {
        return $this->getKey() === null
            ? null
            : sprintf('SUB%06d', (int) $this->getKey());
    }

    public function effectiveStatus(?CarbonInterface $now = null): string
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));

        if (in_array($this->status, ['active', 'trial'], true)) {
            if ($this->starts_at && $this->starts_at->gt($now)) {
                return 'scheduled';
            }

            if ($this->expires_at) {
            if ($this->expires_at->lt($now)) {
                return 'expired';
            }
            }
        }

        return $this->status;
    }

    public function isCurrentlyValid(?CarbonInterface $now = null): bool
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));

        return in_array($this->status, ['active', 'trial'], true)
            && $this->starts_at
            && $this->expires_at
            && $this->starts_at->lte($now)
            && $this->expires_at->gte($now);
    }
}
