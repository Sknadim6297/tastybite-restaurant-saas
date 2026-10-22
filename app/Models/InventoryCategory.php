<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCategory extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'description', 'status'];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'category_id');
    }
}
