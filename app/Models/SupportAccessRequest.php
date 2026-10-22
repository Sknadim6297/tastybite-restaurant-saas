<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportAccessRequest extends Model
{
    public const RESTAURANT_DASHBOARD_MENU_MANAGE = 'dashboard_menu_manage';

    protected $fillable = [
        'restaurant_id',
        'requester_admin_id',
        'reviewer_admin_id',
        'requested_scope',
        'reason',
        'requested_duration_minutes',
        'status',
        'review_reason',
        'reviewed_at',
        'access_started_at',
        'access_expires_at',
        'access_ended_at',
        'access_revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_datetime',
            'requested_duration_minutes' => 'integer',
            'access_started_at' => 'immutable_datetime',
            'access_expires_at' => 'immutable_datetime',
            'access_ended_at' => 'immutable_datetime',
            'access_revoked_at' => 'immutable_datetime',
        ];
    }

    public function hasActiveDashboardAccess(): bool
    {
        return $this->status === 'approved'
            && $this->requested_scope === self::RESTAURANT_DASHBOARD_MENU_MANAGE
            && $this->reviewer_admin_id !== null
            && $this->reviewer_admin_id !== $this->requester_admin_id
            && $this->access_started_at !== null
            && $this->access_expires_at?->isFuture()
            && $this->access_ended_at === null
            && $this->access_revoked_at === null;
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'requester_admin_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'reviewer_admin_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportAccessRequestEvent::class)->orderByDesc('created_at');
    }
}
