<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantPosOrderItemIngredient extends Model
{
    protected $fillable = [
        'restaurant_id', 'order_item_id', 'inventory_item_id', 'unit_id', 'quantity_per_serving',
    ];

    protected function casts(): array
    {
        return ['quantity_per_serving' => 'decimal:6'];
    }
}
