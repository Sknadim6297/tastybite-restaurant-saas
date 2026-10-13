<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuperAdminNotification extends Model
{
    protected $fillable = [
        'super_admin_id',
        'event_key',
        'category',
        'title',
        'description',
        'url',
        'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class);
    }
}
