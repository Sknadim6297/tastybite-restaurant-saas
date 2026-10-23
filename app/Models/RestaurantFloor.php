<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantFloor extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'code', 'display_order', 'description', 'is_active', 'archived_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(RestaurantSection::class, 'floor_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'floor_id');
    }
}
