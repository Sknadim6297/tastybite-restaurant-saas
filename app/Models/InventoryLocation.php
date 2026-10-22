<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLocation extends Model
{
    protected $fillable = ['restaurant_id', 'name', 'code', 'description', 'status'];

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class, 'location_id');
    }
}
