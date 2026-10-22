<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'vendor_code',
        'name',
        'contact_person',
        'mobile',
        'alternate_mobile',
        'email',
        'category',
        'status',
        'business_name',
        'gstin',
        'pan_number',
        'registration_number',
        'payment_terms_days',
        'credit_limit',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'account_holder_name',
        'bank_name',
        'account_number',
        'ifsc_code',
        'bank_branch',
        'upi_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'account_number' => 'encrypted',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(VendorPurchaseOrder::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(VendorGoodsReceipt::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(VendorBill::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(VendorFinancialAdjustment::class);
    }
}