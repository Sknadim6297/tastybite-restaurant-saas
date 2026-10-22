<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorGoodsReceipt extends Model
{
    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new \LogicException('Goods receipt records cannot be deleted.');
        });
    }

    protected $fillable = [
        'restaurant_id', 'purchase_order_id', 'vendor_id', 'grn_number', 'idempotency_key',
        'received_date', 'received_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['received_date' => 'immutable_date'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(VendorPurchaseOrder::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorGoodsReceiptItem::class, 'goods_receipt_id');
    }
}