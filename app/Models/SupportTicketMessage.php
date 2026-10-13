<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicketMessage extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'author_user_id',
        'author_super_admin_id',
        'visibility',
        'body',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function authorAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'author_super_admin_id');
    }
}
