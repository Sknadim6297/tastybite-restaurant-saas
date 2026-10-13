<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantSupportTicketNotification extends Model
{
    protected $fillable = [
        'user_id',
        'support_ticket_id',
        'event_key',
        'title',
        'description',
        'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }
}
