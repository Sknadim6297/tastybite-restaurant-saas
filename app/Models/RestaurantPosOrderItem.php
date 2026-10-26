<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantPosOrderItem extends Model
{
    protected $fillable = [
        'restaurant_id', 'pos_order_id', 'menu_item_id', 'menu_recipe_id', 'recipe_version',
        'item_name', 'quantity', 'unit_price', 'special_instructions',
    ];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2'];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RestaurantPosOrderItemIngredient::class, 'order_item_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(RestaurantMenuItem::class, 'menu_item_id');
    }
}
