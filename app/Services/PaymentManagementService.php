<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PaymentManagementService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SuperAdminNotificationService $notifications,
        private readonly InvoiceManagementService $invoices,
    ) {}

    public function create(array $data, SuperAdmin $actor, ?UploadedFile $proof = null): SubscriptionPayment
    {
        $proofPath = $proof?->store('payment-proofs', 'local');
        if ($proof !== null && ! is_string($proofPath)) {
            throw new \RuntimeException('The payment proof could not be stored securely.');
        }

        try {
            return DB::transaction(function () use ($data, $actor, $proofPath): SubscriptionPayment {
                Restaurant::query()->findOrFail($data['restaurant_id']);
                $plan = SubscriptionPlan::query()->findOrFail($data['subscription_plan_id']);
                if (! $plan->isActive()) {
                    throw ValidationException::withMessages(['subscription_plan_id' => 'Select an active subscription plan.']);
                }

                $payment = SubscriptionPayment::create([
                    'restaurant_id' => $data['restaurant_id'],
                    'subscription_plan_id' => $data['subscription_plan_id'],
                    'plan_name' => $plan->name,
                    'plan_code' => $plan->code,
                    'created_by' => $actor->id,
                    'amount' => $data['amount'],
                    'currency' => strtoupper($data['currency']),
                    'payment_method' => $data['payment_method'],
                    'transaction_reference' => $data['transaction_reference'] ?? null,
                    'status' => 'pending',
                    'paid_at' => CarbonImmutable::parse($data['paid_at'], config('app.timezone'))->utc(),
                    'notes' => $data['notes'] ?? null,
                    'proof_path' => $proofPath,
                ]);

                $this->audit($actor, 'payment_recorded', $payment, [
                    'restaurant_id' => $payment->restaurant_id,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'payment_method' => $payment->payment_method,
                    'status' => $payment->status,
                ]);
                $this->notifications->paymentEvent('payment_submitted', $payment);

                return $payment;
            });
        } catch (\Throwable $exception) {
            if ($proofPath !== null) {
                Storage::disk('local')->delete($proofPath);
            }

            throw $exception;
        }
    }

    public function approve(SubscriptionPayment $payment, SuperAdmin $actor): SubscriptionPayment
    {
        return DB::transaction(function () use ($payment, $actor): SubscriptionPayment {
            $payment = SubscriptionPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Only pending payments can be approved.']);
            }

            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($payment->restaurant_id);
            $plan = SubscriptionPlan::query()->lockForUpdate()->findOrFail($payment->subscription_plan_id);
            if (! $restaurant->isActive()) {
                throw ValidationException::withMessages(['payment' => 'Payments for inactive restaurants cannot activate a subscription.']);
            }
            if (! $plan->isActive()) {
                throw ValidationException::withMessages(['payment' => 'The selected plan is no longer active.']);
            }

            $previous = RestaurantSubscription::query()
                ->where('restaurant_id', $restaurant->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($previous === null) {
                $subscription = $this->subscriptions->assign(
                    $restaurant,
                    $plan,
                    CarbonImmutable::now(config('app.timezone')),
                    null,
                    false,
                    $actor,
                    [
                        'payment_id' => $payment->id,
                        'transaction_reference' => $payment->transaction_reference,
                    ],
                );
            } else {
                $status = $previous->effectiveStatus();
                if (! in_array($status, ['active', 'trial', 'expired'], true)) {
                    throw ValidationException::withMessages([
                        'payment' => 'The restaurant has a suspended or cancelled subscription. Resolve its subscription status before approving this payment.',
                    ]);
                }
                if ($previous->subscription_plan_id !== $plan->id) {
                    throw ValidationException::withMessages([
                        'subscription_plan_id' => 'A renewal payment must use the restaurant subscription’s current plan.',
                    ]);
                }

                $subscription = $this->subscriptions->renew($previous, null, $actor, [
                    'payment_id' => $payment->id,
                    'transaction_reference' => $payment->transaction_reference,
                ]);
            }

            $payment->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(config('app.timezone'))->utc(),
                'restaurant_subscription_id' => $subscription->id,
            ]);

            $this->invoices->issueForApprovedPayment($payment, $subscription, $actor);

            $this->audit($actor, 'payment_approved', $payment, [
                'subscription_id' => $subscription->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
            ]);
            $this->notifications->paymentEvent('payment_approved', $payment);

            return $payment->fresh();
        });
    }

    public function reject(SubscriptionPayment $payment, SuperAdmin $actor, string $reason): SubscriptionPayment
    {
        return DB::transaction(function () use ($payment, $actor, $reason): SubscriptionPayment {
            $payment = SubscriptionPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'pending') {
                throw ValidationException::withMessages(['payment' => 'Only pending payments can be rejected.']);
            }

            $payment->update([
                'status' => 'rejected',
                'rejected_by' => $actor->id,
                'rejected_at' => CarbonImmutable::now(config('app.timezone'))->utc(),
                'rejection_reason' => $reason,
            ]);
            $this->audit($actor, 'payment_rejected', $payment, ['reason' => $reason]);
            $this->notifications->paymentEvent('payment_rejected', $payment);

            return $payment->fresh();
        });
    }

    public function refund(SubscriptionPayment $payment, SuperAdmin $actor, array $data): SubscriptionPaymentRefund
    {
        return DB::transaction(function () use ($payment, $actor, $data): SubscriptionPaymentRefund {
            $payment = SubscriptionPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, ['approved', 'refunded'], true)) {
                throw ValidationException::withMessages(['payment' => 'Only approved payments can be refunded.']);
            }

            $remaining = $payment->remainingRefundableAmount();
            if (
                SubscriptionPayment::toCents((string) $data['amount']) > SubscriptionPayment::toCents($remaining)
                || SubscriptionPayment::toCents($remaining) < 1
            ) {
                throw ValidationException::withMessages(['amount' => 'The refund exceeds the remaining refundable amount.']);
            }

            $refund = SubscriptionPaymentRefund::create([
                'subscription_payment_id' => $payment->id,
                'admin_id' => $actor->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'refunded_at' => CarbonImmutable::parse($data['refunded_at'], config('app.timezone'))->utc(),
                'reason' => $data['reason'],
            ]);

            if (SubscriptionPayment::toCents($payment->remainingRefundableAmount()) === 0) {
                $payment->update(['status' => 'refunded']);
            }

            $this->audit($actor, 'payment_refund_recorded', $payment, [
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
                'method' => $refund->method,
                'reason' => $refund->reason,
                'external_return_confirmed' => false,
            ]);
            $this->notifications->paymentEvent('refund_recorded', $payment);

            return $refund;
        });
    }

    private function audit(SuperAdmin $actor, string $action, SubscriptionPayment $payment, array $details): void
    {
        RestaurantAuditLog::create([
            'actor_id' => $actor->id,
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => 'subscription_payment',
            'target_id' => $payment->id,
            'details' => $details,
        ]);
    }
}
