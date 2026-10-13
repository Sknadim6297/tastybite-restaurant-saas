<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'support_ticket_status_history';

    protected $fillable = [
        'support_ticket_id',
        'actor_user_id',
        'actor_super_admin_id',
        'previous_status',
        'new_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'actor_super_admin_id');
    }
}
