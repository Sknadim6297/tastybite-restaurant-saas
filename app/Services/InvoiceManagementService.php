<?php

namespace App\Services;

use App\Models\RestaurantSubscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceManagementService
{
    public function issueForApprovedPayment(
        SubscriptionPayment $payment,
        RestaurantSubscription $subscription,
        ?SuperAdmin $actor,
    ): SubscriptionInvoice {
        if ($payment->status !== 'approved' || $payment->restaurant_subscription_id !== $subscription->id) {
            throw ValidationException::withMessages([
                'payment' => 'Only an approved payment linked to its activated subscription can issue a receipt.',
            ]);
        }

        return DB::transaction(function () use ($payment, $subscription, $actor): SubscriptionInvoice {
            $payment = SubscriptionPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $existing = SubscriptionInvoice::query()
                ->where('subscription_payment_id', $payment->id)
                ->first();
            if ($existing) {
                return $existing;
            }

            $issuedAt = $payment->approved_at
                ? CarbonImmutable::instance($payment->approved_at)
                : CarbonImmutable::now(config('app.timezone'))->utc();

            return SubscriptionInvoice::create([
                'subscription_payment_id' => $payment->id,
                'restaurant_id' => $payment->restaurant_id,
                'subscription_plan_id' => $payment->subscription_plan_id,
                'restaurant_subscription_id' => $subscription->id,
                'created_by' => $actor?->id,
                'invoice_number' => sprintf('TB-RCT-%s-%08d', $issuedAt->timezone(config('app.timezone'))->format('Ym'), $payment->id),
                'document_type' => 'non_tax_payment_receipt',
                'subtotal' => $payment->amount,
                'tax_amount' => '0.00',
                'total' => $payment->amount,
                'issue_date' => $issuedAt,
                'billing_period_start' => $subscription->starts_at,
                'billing_period_end' => $subscription->expires_at,
            ]);
        });
    }
}
