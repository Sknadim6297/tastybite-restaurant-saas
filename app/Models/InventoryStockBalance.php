<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockBalance extends Model
{
    protected $fillable = ['restaurant_id', 'inventory_item_id', 'location_id', 'quantity', 'average_unit_cost'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'average_unit_cost' => 'decimal:6'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
