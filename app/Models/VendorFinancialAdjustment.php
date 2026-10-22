<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorFinancialAdjustment extends Model
{
    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new \LogicException('Vendor financial adjustments cannot be deleted.');
        });
    }

    protected $fillable = [
        'restaurant_id', 'vendor_id', 'adjustment_reference', 'entry_type',
        'amount', 'entry_date', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'entry_date' => 'immutable_date',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}