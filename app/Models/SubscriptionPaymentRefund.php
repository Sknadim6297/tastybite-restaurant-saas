<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPaymentRefund extends Model
{
    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('Refund records are immutable.');
        });
        static::deleting(static function (): void {
            throw new \LogicException('Refund records cannot be deleted.');
        });
    }

    protected $fillable = [
        'subscription_payment_id',
        'admin_id',
        'amount',
        'method',
        'reference',
        'refunded_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function administrator(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'admin_id');
    }
}
