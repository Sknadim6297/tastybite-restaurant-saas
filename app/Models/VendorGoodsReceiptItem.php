<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorGoodsReceiptItem extends Model
{
    protected $fillable = [
        'restaurant_id', 'goods_receipt_id', 'purchase_order_item_id', 'item_name', 'unit',
        'ordered_quantity', 'previously_received_quantity', 'received_quantity',
        'rejected_quantity', 'accepted_quantity',
    ];

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'decimal:3',
            'previously_received_quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'rejected_quantity' => 'decimal:3',
            'accepted_quantity' => 'decimal:3',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(VendorGoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(VendorPurchaseOrderItem::class, 'purchase_order_item_id');
    }
}