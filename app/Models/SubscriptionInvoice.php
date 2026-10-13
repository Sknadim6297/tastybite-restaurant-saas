<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $invoice_number
 * @property string $document_type
 * @property string $subtotal
 * @property string $tax_amount
 * @property string $total
 * @property \Illuminate\Support\Carbon $issue_date
 */
class SubscriptionInvoice extends Model
{
    protected $fillable = [
        'subscription_payment_id',
        'restaurant_id',
        'subscription_plan_id',
        'restaurant_subscription_id',
        'created_by',
        'invoice_number',
        'document_type',
        'subtotal',
        'tax_amount',
        'total',
        'issue_date',
        'billing_period_start',
        'billing_period_end',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issue_date' => 'immutable_datetime',
            'billing_period_start' => 'immutable_datetime',
            'billing_period_end' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(RestaurantSubscription::class, 'restaurant_subscription_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'created_by');
    }

    public function netRetainedAmount(): string
    {
        $payment = $this->payment;

        return $payment
            ? $payment->remainingRefundableAmount()
            : '0.00';
    }

    public function recordedRefundAmount(): string
    {
        $original = SubscriptionPayment::toCents((string) $this->total);
        $retained = SubscriptionPayment::toCents($this->netRetainedAmount());

        return SubscriptionPayment::formatCents(max(0, $original - $retained));
    }
}
