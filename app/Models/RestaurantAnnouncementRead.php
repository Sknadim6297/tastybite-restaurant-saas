<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantAnnouncementRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'platform_announcement_id',
        'restaurant_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(PlatformAnnouncement::class, 'platform_announcement_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
