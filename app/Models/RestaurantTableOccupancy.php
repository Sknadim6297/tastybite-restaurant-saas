<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantTableOccupancy extends Model
{
    protected $fillable = [
        'restaurant_id', 'table_id', 'group_id', 'pos_order_id', 'started_by', 'ended_by', 'started_by_admin_id',
        'ended_by_admin_id', 'guest_count', 'started_at', 'ended_at',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(RestaurantTable::class, 'table_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantPosOrder::class, 'pos_order_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RestaurantTableGroup::class, 'group_id');
    }
}
