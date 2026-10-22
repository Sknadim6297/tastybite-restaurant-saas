<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryUnit extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'abbreviation', 'dimension', 'status'];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'base_unit_id');
    }
}
