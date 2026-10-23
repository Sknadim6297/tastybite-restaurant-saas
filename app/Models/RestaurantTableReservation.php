<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantTableReservation extends Model
{
    protected $fillable = [
        'restaurant_id', 'floor_id', 'section_id', 'table_id', 'created_by', 'created_by_admin_id', 'reference',
        'customer_name', 'phone', 'email', 'starts_at', 'duration_minutes', 'party_size',
        'status', 'special_requests', 'internal_notes',
    ];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(RestaurantFloor::class, 'floor_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(RestaurantSection::class, 'section_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }
}
