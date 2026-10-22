<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItemBatch extends Model
{
    protected $fillable = [
        'restaurant_id', 'inventory_item_id', 'location_id', 'vendor_id', 'batch_number',
        'manufactured_at', 'expires_at', 'received_quantity', 'available_quantity', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'received_quantity' => 'decimal:6',
            'available_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:6',
        ];
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
