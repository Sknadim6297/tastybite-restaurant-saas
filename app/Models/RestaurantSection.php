<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RestaurantSection extends Model
{
    protected $fillable = ['restaurant_id', 'floor_id', 'name', 'code', 'description', 'sort_order', 'is_active', 'archived_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(RestaurantFloor::class, 'floor_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class, 'section_id');
    }
}
