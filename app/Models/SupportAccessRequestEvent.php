<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAccessRequestEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'support_access_request_id',
        'actor_admin_id',
        'action',
        'previous_status',
        'new_status',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'actor_admin_id');
    }
}
