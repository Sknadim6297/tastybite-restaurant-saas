<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantSubscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\PaymentManagementService;
use App\Services\PlatformSettingsService;
use App\Services\RestaurantAccessContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class SubscriptionStatusController extends Controller
{
    public function __construct(
        private readonly PaymentManagementService $payments,
        private readonly PlatformSettingsService $settings,
    ) {}

    public function show(RestaurantAccessContext $context): View
    {
        $user = $context->owner();

        $restaurant = $user->restaurant;
        $now = CarbonImmutable::now(config('app.timezone'));
        $subscriptions = $restaurant->subscriptions()
            ->with(['payments' => fn ($query) => $query->with(['refunds', 'invoice'])->latest('paid_at')])
            ->orderByDesc('created_at')
            ->get();
        $subscription = $restaurant->currentSubscriptionAt($now);
        $status = $subscription?->effectiveStatus($now);
        $daysRemaining = 0;

        if ($subscription instanceof RestaurantSubscription && $subscription->expires_at->gte($now)) {
            $expiryLocal = $subscription->expires_at->timezone(config('app.timezone'));
            $daysRemaining = max(0, (int) $now->startOfDay()->diffInDays($expiryLocal->startOfDay(), false));
        }

        $paymentSettings = $this->settings->group('payment');

        return view('restaurant.subscription', [
            'user' => $user,
            'restaurant' => $restaurant,
            'subscription' => $subscription,
            'subscriptions' => $subscriptions,
            'paymentSummaries' => $this->payments->subscriptionPaymentSummaries($subscriptions),
            'paymentSettings' => $paymentSettings,
            'subscriptionStatus' => $status,
            'daysRemaining' => $daysRemaining,
        ]);
    }

    public function submitPayment(Request $request, RestaurantSubscription $restaurantSubscription): RedirectResponse
    {
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $user->isOwner(), 403);

        $paymentSettings = $this->settings->group('payment');
        abort_unless($paymentSettings['manual_enabled'], 403, 'Payment submission is currently unavailable.');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'payment_method' => ['required', Rule::in($paymentSettings['allowed_methods'])],
            'transaction_reference' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('subscription_payments', 'transaction_reference')->where(
                    fn ($query) => $query->where('payment_method', $request->input('payment_method')),
                ),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => [
                $paymentSettings['proof_required'] ? 'required' : 'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:5120',
            ],
        ]);

        $this->payments->submitOwnerPayment(
            $restaurantSubscription,
            $user,
            $validated,
            $request->file('proof'),
        );

        return redirect()->route('restaurant.subscription')
            ->with('status', 'Payment details submitted for verification.');
    }

    public function invoicePdf(SubscriptionInvoice $invoice, RestaurantAccessContext $context): BaseResponse
    {
        $user = $context->owner();
        abort_unless($invoice->restaurant_id === $user->restaurant_id, 404);

        $invoice->load([
            'payment.restaurant.owners',
            'payment.plan',
            'payment.subscription',
            'payment.refunds.administrator',
        ]);

        return Pdf::loadView('super-admin.invoices.pdf', ['invoice' => $invoice])
            ->setPaper('a4')
            ->download($invoice->invoice_number.'.pdf');
    }
}
