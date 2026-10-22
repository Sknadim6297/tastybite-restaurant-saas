<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'restaurant_id', 'inventory_item_id', 'location_id', 'batch_id', 'performed_by',
        'reversal_of_id', 'movement_number', 'movement_type', 'quantity_in', 'quantity_out',
        'reason', 'balance_after', 'unit_cost', 'value', 'source_type', 'source_id', 'source_line_id',
        'idempotency_key', 'reference', 'occurred_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_in' => 'decimal:6',
            'quantity_out' => 'decimal:6',
            'balance_after' => 'decimal:6',
            'unit_cost' => 'decimal:6',
            'value' => 'decimal:6',
            'occurred_at' => 'immutable_datetime',
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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryItemBatch::class, 'batch_id');
    }
}
