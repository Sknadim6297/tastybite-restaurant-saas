<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = [
        'ticket_number',
        'restaurant_id',
        'created_by_user_id',
        'assigned_admin_id',
        'subject',
        'category',
        'priority',
        'status',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return ['last_activity_at' => 'immutable_datetime'];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'assigned_admin_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SupportTicketStatusHistory::class);
    }
}
