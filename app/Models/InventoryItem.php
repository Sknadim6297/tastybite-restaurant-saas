<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'restaurant_id', 'category_id', 'base_unit_id', 'purchase_unit_id', 'consumption_unit_id',
        'preferred_vendor_id', 'default_location_id', 'item_code', 'name', 'sku', 'description',
        'reorder_level', 'reorder_quantity', 'standard_cost', 'tax_rate', 'track_expiry',
        'track_batches', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'reorder_level' => 'decimal:6',
            'reorder_quantity' => 'decimal:6',
            'standard_cost' => 'decimal:6',
            'tax_rate' => 'decimal:2',
            'track_expiry' => 'boolean',
            'track_batches' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'category_id');
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'base_unit_id');
    }

    public function purchaseUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'purchase_unit_id');
    }

    public function consumptionUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'consumption_unit_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
