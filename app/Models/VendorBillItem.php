<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorBillItem extends Model
{
    protected $fillable = [
        'restaurant_id', 'vendor_bill_id', 'item_name', 'quantity', 'unit',
        'unit_price', 'discount_amount', 'tax_rate', 'tax_amount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }
}