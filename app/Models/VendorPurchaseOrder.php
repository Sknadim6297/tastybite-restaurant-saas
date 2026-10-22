<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorPurchaseOrder extends Model
{
    protected static function booted(): void
    {
        static::deleting(static function (self $order): void {
            if ($order->goodsReceipts()->exists() || $order->bills()->where('status', '!=', 'draft')->exists()) {
                throw new \LogicException('Purchase orders with financial or receipt history cannot be deleted.');
            }
        });
    }

    protected $fillable = [
        'restaurant_id', 'vendor_id', 'po_number', 'order_date', 'expected_delivery_date',
        'status', 'subtotal', 'discount_total', 'tax_total', 'grand_total', 'notes',
        'created_by', 'confirmed_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'immutable_date',
            'expected_delivery_date' => 'immutable_date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorPurchaseOrderItem::class, 'purchase_order_id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(VendorGoodsReceipt::class, 'purchase_order_id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(VendorBill::class, 'purchase_order_id');
    }
}