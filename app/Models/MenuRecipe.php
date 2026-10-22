<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuRecipe extends Model
{
    protected $fillable = [
        'restaurant_id', 'menu_item_id', 'version', 'yield_quantity', 'is_active', 'preparation_notes',
    ];

    protected function casts(): array
    {
        return ['yield_quantity' => 'decimal:6', 'is_active' => 'boolean'];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(RestaurantMenuItem::class, 'menu_item_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(MenuRecipeIngredient::class);
    }
}
