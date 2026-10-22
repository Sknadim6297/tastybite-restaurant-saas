<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuRecipeIngredient extends Model
{
    protected $fillable = ['restaurant_id', 'menu_recipe_id', 'inventory_item_id', 'unit_id', 'quantity', 'base_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'base_quantity' => 'decimal:6'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'unit_id');
    }
}
