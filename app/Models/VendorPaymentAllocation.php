<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorPaymentAllocation extends Model
{
    protected $fillable = ['restaurant_id', 'vendor_payment_id', 'vendor_bill_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }
}