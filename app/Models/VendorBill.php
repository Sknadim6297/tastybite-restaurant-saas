<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorBill extends Model
{
    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new \LogicException('Vendor bills cannot be deleted.');
        });
    }

    protected $fillable = [
        'restaurant_id', 'vendor_id', 'purchase_order_id', 'goods_receipt_id', 'bill_number',
        'vendor_invoice_number', 'invoice_date', 'due_date', 'taxable_amount', 'tax_amount',
        'total_amount', 'status', 'attachment_path', 'created_by', 'posted_by', 'posted_at',
        'cancelled_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'taxable_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(VendorPurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(VendorGoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorBillItem::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }
}