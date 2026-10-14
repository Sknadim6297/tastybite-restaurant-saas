<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionPayment extends Model
{
    protected static function booted(): void
    {
        static::deleting(static function (): void {
            throw new \LogicException('Financial payment records cannot be deleted.');
        });
    }

    protected $fillable = [
        'restaurant_id',
        'subscription_plan_id',
        'created_by',
        'approved_by',
        'rejected_by',
        'restaurant_subscription_id',
        'plan_name',
        'plan_code',
        'amount',
        'currency',
        'payment_method',
        'transaction_reference',
        'status',
        'paid_at',
        'notes',
        'proof_path',
        'rejection_reason',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    public function getFormattedIdAttribute(): ?string
    {
        return $this->getKey() === null
            ? null
            : sprintf('PAY%06d', (int) $this->getKey());
    }

    public function getPaymentMethodLabelAttribute(): ?string
    {
        return match ($this->payment_method) {
            'upi' => 'UPI',
            'bank_transfer' => 'Bank Transfer',
            'cash' => 'Cash',
            'other' => 'Other',
            default => $this->payment_method
                ? ucfirst(str_replace('_', ' ', $this->payment_method))
                : null,
        };
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'rejected_by');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(RestaurantSubscription::class, 'restaurant_subscription_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(SubscriptionPaymentRefund::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(SubscriptionInvoice::class);
    }

    public function remainingRefundableAmount(): string
    {
        if ($this->relationLoaded('refunds')) {
            $refundedCents = $this->refunds->sum(
                fn (SubscriptionPaymentRefund $refund): int => self::toCents((string) $refund->amount),
            );
        } else {
            $refundedCents = self::toCents((string) $this->refunds()->sum('amount'));
        }

        return self::formatCents(max(0, self::toCents((string) $this->amount) - $refundedCents));
    }

    public function isDemo(): bool
    {
        return str_starts_with((string) $this->notes, 'DEMO DATA ONLY');
    }

    public static function toCents(string $amount): int
    {
        if (! preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
            throw new \InvalidArgumentException('Invalid decimal amount.');
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    public static function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
