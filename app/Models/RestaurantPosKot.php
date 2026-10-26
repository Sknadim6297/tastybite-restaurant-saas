<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantPosKot extends Model
{
    protected $fillable = [
        'restaurant_id', 'pos_order_id', 'kot_number', 'restaurant_name', 'order_number',
        'items', 'order_type', 'table_number', 'reprint_count', 'printed_at', 'last_printed_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'printed_at' => 'immutable_datetime',
            'last_printed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(RestaurantPosOrder::class, 'pos_order_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
