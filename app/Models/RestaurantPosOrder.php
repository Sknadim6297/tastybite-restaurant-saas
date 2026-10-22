<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantPosOrder extends Model
{
    protected $fillable = [
        'restaurant_id', 'created_by', 'order_number', 'status', 'idempotency_key',
        'subtotal', 'discount', 'tax', 'total', 'completed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantPosOrderItem::class, 'pos_order_id');
    }
}
