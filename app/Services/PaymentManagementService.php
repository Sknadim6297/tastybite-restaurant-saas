<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPaymentRefund;
use App\Models\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PaymentManagementService
{
    public function __construct(
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
                $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($data['restaurant_id']);
                $subscription = RestaurantSubscription::query()
                    ->where('restaurant_id', $restaurant->id)
                    ->lockForUpdate()
                    ->find($data['restaurant_subscription_id']);
                if (! $subscription) {
                    throw ValidationException::withMessages(['restaurant_subscription_id' => 'Select a subscription belonging to the selected restaurant.']);
                }

                $summary = $this->subscriptionPaymentSummaries(new EloquentCollection([$subscription]))[$subscription->id];
                if (SubscriptionPayment::toCents((string) $data['amount']) > SubscriptionPayment::toCents($summary['available_amount'])) {
                    throw ValidationException::withMessages(['amount' => 'The payment amount exceeds the available subscription balance.']);
                }

                $payment = SubscriptionPayment::create([
                    'restaurant_id' => $data['restaurant_id'],
                    'subscription_plan_id' => $subscription->subscription_plan_id,
                    'restaurant_subscription_id' => $subscription->id,
                    'plan_name' => $subscription->plan_name,
                    'plan_code' => $subscription->plan_code,
                    'created_by' => $actor->id,
                    'amount' => $data['amount'],
                    'currency' => $subscription->currency,
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
            if (! $restaurant->isActive()) {
                throw ValidationException::withMessages(['payment' => 'Payments for inactive restaurants cannot be approved.']);
            }
            if (! $payment->restaurant_subscription_id) {
                throw ValidationException::withMessages(['payment' => 'This payment is not linked to an existing subscription. Record a new payment against the correct subscription.']);
            }

            $subscription = RestaurantSubscription::query()
                ->where('restaurant_id', $restaurant->id)
                ->lockForUpdate()
                ->find($payment->restaurant_subscription_id);
            if (! $subscription || $payment->subscription_plan_id !== $subscription->subscription_plan_id) {
                throw ValidationException::withMessages(['payment' => 'The linked subscription does not match this payment.']);
            }

            $summary = $this->subscriptionPaymentSummaries(new EloquentCollection([$subscription]), $payment->id)[$subscription->id];
            if (SubscriptionPayment::toCents((string) $payment->amount) > SubscriptionPayment::toCents($summary['available_amount'])) {
                throw ValidationException::withMessages(['payment' => 'This payment exceeds the remaining subscription balance and cannot be approved.']);
            }

            $payment->update([
                'status' => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => CarbonImmutable::now(config('app.timezone'))->utc(),
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

    /**
     * @param  EloquentCollection<int, RestaurantSubscription>  $subscriptions
     * @return array<int, array{total_payable: string, already_paid: string, outstanding: string, pending_amount: string, available_amount: string, payment_status: string}>
     */
    public function subscriptionPaymentSummaries(EloquentCollection $subscriptions, ?int $excludePendingPaymentId = null): array
    {
        $summaries = [];
        $subscriptionIds = $subscriptions->modelKeys();

        foreach ($subscriptions as $subscription) {
            $summaries[$subscription->id] = [
                'total_payable' => SubscriptionPayment::formatCents(SubscriptionPayment::toCents((string) $subscription->price)),
                'already_paid' => '0.00',
                'outstanding' => SubscriptionPayment::formatCents(SubscriptionPayment::toCents((string) $subscription->price)),
                'pending_amount' => '0.00',
                'available_amount' => SubscriptionPayment::formatCents(SubscriptionPayment::toCents((string) $subscription->price)),
                'payment_status' => 'Unpaid',
            ];
        }

        if ($subscriptionIds === []) {
            return $summaries;
        }

        $payments = SubscriptionPayment::query()
            ->whereIn('restaurant_subscription_id', $subscriptionIds)
            ->whereIn('status', ['approved', 'refunded', 'pending'])
            ->with('refunds:id,subscription_payment_id,amount')
            ->get(['id', 'restaurant_subscription_id', 'amount', 'status']);

        $paidCents = array_fill_keys($subscriptionIds, 0);
        $pendingCents = array_fill_keys($subscriptionIds, 0);

        foreach ($payments as $payment) {
            $subscriptionId = (int) $payment->restaurant_subscription_id;
            if ($payment->status === 'pending') {
                if ($payment->id !== $excludePendingPaymentId) {
                    $pendingCents[$subscriptionId] += SubscriptionPayment::toCents((string) $payment->amount);
                }

                continue;
            }

            $refundCents = $payment->refunds->sum(
                fn (SubscriptionPaymentRefund $refund): int => SubscriptionPayment::toCents((string) $refund->amount),
            );
            $paidCents[$subscriptionId] += max(
                0,
                SubscriptionPayment::toCents((string) $payment->amount) - $refundCents,
            );
        }

        foreach ($subscriptions as $subscription) {
            $id = $subscription->id;
            $totalCents = SubscriptionPayment::toCents((string) $subscription->price);
            $paid = min($paidCents[$id], $totalCents);
            $outstanding = max(0, $totalCents - $paid);
            $available = max(0, $outstanding - $pendingCents[$id]);
            $summaries[$id] = [
                'total_payable' => SubscriptionPayment::formatCents($totalCents),
                'already_paid' => SubscriptionPayment::formatCents($paid),
                'outstanding' => SubscriptionPayment::formatCents($outstanding),
                'pending_amount' => SubscriptionPayment::formatCents($pendingCents[$id]),
                'available_amount' => SubscriptionPayment::formatCents($available),
                'payment_status' => $outstanding === 0
                    ? 'Fully Paid'
                    : ($paid > 0 ? 'Partially Paid' : ($pendingCents[$id] > 0 ? 'Pending Approval' : 'Unpaid')),
            ];
        }

        return $summaries;
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
