<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Restaurant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'status',
        'approval_status',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owners(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'owner');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(RestaurantAuditLog::class, 'target_id')
            ->where('target_type', 'restaurant')
            ->orderByDesc('created_at');
    }

    public function approvalHistory(): HasMany
    {
        return $this->hasMany(RestaurantApprovalHistory::class)->orderByDesc('created_at');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(RestaurantSubscription::class);
    }

    public function currentSubscription(): HasOne
    {
        return $this->hasOne(RestaurantSubscription::class)->latestOfMany();
    }

    public function currentSubscriptionAt(CarbonImmutable $at): ?RestaurantSubscription
    {
        $instant = $at->utc();

        return $this->subscriptions()
            ->whereIn('status', ['active', 'trial'])
            ->where('starts_at', '<=', $instant)
            ->where('expires_at', '>=', $instant)
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first() ?? $this->currentSubscription()->first();
    }

    public function subscriptionEvents(): HasMany
    {
        return $this->hasMany(RestaurantSubscriptionEvent::class)->orderByDesc('created_at');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

}
