<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorPayment extends Model
{
    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new \LogicException('Vendor payment records cannot be deleted.');
        });
    }

    protected $fillable = [
        'restaurant_id', 'vendor_id', 'payment_reference', 'payment_type', 'reverses_payment_id',
        'payment_date', 'amount', 'payment_method', 'transaction_reference', 'proof_path',
        'idempotency_key', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'immutable_date',
            'amount' => 'decimal:2',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_payment_id');
    }

    public function reversal(): HasMany
    {
        return $this->hasMany(self::class, 'reverses_payment_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(VendorPaymentAllocation::class);
    }
}