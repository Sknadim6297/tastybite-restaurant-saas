<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantSubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Services\SuperAdminNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function __construct(private readonly SuperAdminNotificationService $notifications) {}

    public function assign(
        Restaurant $restaurant,
        SubscriptionPlan $plan,
        CarbonImmutable $startsAt,
        ?CarbonImmutable $manualExpiry,
        bool $trial,
        SuperAdmin $actor,
        array $eventDetails = [],
    ): RestaurantSubscription {
        return DB::transaction(function () use ($restaurant, $plan, $startsAt, $manualExpiry, $trial, $actor, $eventDetails): RestaurantSubscription {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($restaurant->id);
            $plan = SubscriptionPlan::query()->lockForUpdate()->findOrFail($plan->id);

            if (! $restaurant->isActive()) {
                throw ValidationException::withMessages(['restaurant_id' => 'Only an active restaurant can receive a subscription.']);
            }

            if (! $plan->isActive()) {
                throw ValidationException::withMessages(['subscription_plan_id' => 'Select an active subscription plan.']);
            }

            if ($trial && (! $plan->trial_available || ! $plan->trial_duration_days)) {
                throw ValidationException::withMessages(['trial' => 'The selected plan does not offer a trial.']);
            }

            $startsAt = $startsAt->startOfDay();
            $expiresAt = $manualExpiry
                ? $manualExpiry->endOfDay()
                : $this->planExpiry($plan->duration_months, $plan->duration_days, $startsAt, $plan->trial_duration_days, $trial);

            $this->assertExpiryAfterStart($startsAt, $expiresAt);
            if ($expiresAt->lt(CarbonImmutable::now(config('app.timezone')))) {
                throw ValidationException::withMessages(['expiry_date' => 'A new subscription cannot already be expired.']);
            }
            $this->assertNoOverlap($restaurant, $startsAt->utc(), $expiresAt->utc());

            $subscription = RestaurantSubscription::create([
                'restaurant_id' => $restaurant->id,
                'subscription_plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_code' => $plan->code,
                'price' => $plan->price,
                'currency' => $plan->currency,
                'billing_period' => $plan->billing_period,
                'duration_months' => $plan->duration_months,
                'duration_days' => $plan->duration_days,
                'status' => $trial ? 'trial' : 'active',
                'is_trial' => $trial,
                'starts_at' => $startsAt->utc(),
                'expires_at' => $expiresAt->utc(),
                'created_by' => $actor->id,
            ]);

            $this->recordEvent($subscription, $actor, 'assigned', null, $subscription->status, [
                'manual_expiry' => $manualExpiry !== null,
                'price' => $subscription->price,
                'currency' => $subscription->currency,
            ] + $eventDetails);
            if ($subscription->status === 'active') {
                $this->notifyActivation($subscription);
            }

            return $subscription;
        });
    }

    public function renew(
        RestaurantSubscription $subscription,
        ?CarbonImmutable $manualExpiry,
        SuperAdmin $actor,
        array $eventDetails = [],
    ): RestaurantSubscription {
        return DB::transaction(function () use ($subscription, $manualExpiry, $actor, $eventDetails): RestaurantSubscription {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($subscription->restaurant_id);
            $previous = RestaurantSubscription::query()
                ->where('restaurant_id', $restaurant->id)
                ->lockForUpdate()
                ->findOrFail($subscription->id);

            if (! in_array($previous->effectiveStatus(), ['active', 'trial', 'expired'], true)) {
                throw ValidationException::withMessages(['subscription' => 'Only an active or expired subscription can be renewed.']);
            }

            $now = CarbonImmutable::now(config('app.timezone'));
            $previousExpiryLocal = $previous->expires_at->timezone(config('app.timezone'));
            $startsAt = $previousExpiryLocal->gte($now)
                ? $previousExpiryLocal->startOfDay()->addDay()
                : $now->startOfDay();
            $expiresAt = $manualExpiry
                ? $manualExpiry->endOfDay()
                : $this->planExpiry($previous->duration_months, $previous->duration_days, $startsAt);

            $this->assertExpiryAfterStart($startsAt, $expiresAt);
            $this->assertNoOverlap($restaurant, $startsAt->utc(), $expiresAt->utc(), $previous->id);

            $renewal = RestaurantSubscription::create([
                'restaurant_id' => $restaurant->id,
                'subscription_plan_id' => $previous->subscription_plan_id,
                'plan_name' => $previous->plan_name,
                'plan_code' => $previous->plan_code,
                'price' => $previous->price,
                'currency' => $previous->currency,
                'billing_period' => $previous->billing_period,
                'duration_months' => $previous->duration_months,
                'duration_days' => $previous->duration_days,
                'status' => 'active',
                'is_trial' => false,
                'starts_at' => $startsAt->utc(),
                'expires_at' => $expiresAt->utc(),
                'created_by' => $actor->id,
                'renewal_of_id' => $previous->id,
            ]);

            $this->recordEvent($renewal, $actor, 'renewed', $previous->effectiveStatus(), 'active', [
                'renewed_from_id' => $previous->id,
                'price' => $renewal->price,
                'currency' => $renewal->currency,
                'manual_expiry' => $manualExpiry !== null,
            ] + $eventDetails);
            $this->notifyActivation($renewal, true);

            return $renewal;
        });
    }

    public function extend(
        RestaurantSubscription $subscription,
        CarbonImmutable $newExpiry,
        SuperAdmin $actor,
    ): RestaurantSubscription {
        return DB::transaction(function () use ($subscription, $newExpiry, $actor): RestaurantSubscription {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($subscription->restaurant_id);
            $subscription = RestaurantSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $previousExpiry = $subscription->expires_at;

            if (! $subscription->isCurrentlyValid()) {
                throw ValidationException::withMessages(['expires_at' => 'Only a currently active or trial subscription can be extended. Use Renew for an expired subscription.']);
            }

            $newExpiry = $newExpiry->endOfDay();

            if ($newExpiry->utc()->lte($previousExpiry)) {
                throw ValidationException::withMessages(['expires_at' => 'The new expiry date must be later than the current expiry date.']);
            }

            $this->assertNoOverlap($restaurant, $subscription->starts_at, $newExpiry->utc(), $subscription->id);

            $previousStatus = $subscription->effectiveStatus();
            $subscription->update(['expires_at' => $newExpiry->utc()]);

            $this->recordEvent($subscription, $actor, 'extended', $previousStatus, $subscription->status, [
                'previous_expires_at' => $previousExpiry->toIso8601String(),
                'new_expires_at' => $newExpiry->toIso8601String(),
            ]);

            return $subscription;
        });
    }

    public function changeStatus(
        RestaurantSubscription $subscription,
        string $newStatus,
        SuperAdmin $actor,
        array $eventDetails = [],
    ): RestaurantSubscription {
        return DB::transaction(function () use ($subscription, $newStatus, $actor, $eventDetails): RestaurantSubscription {
            $restaurant = Restaurant::query()->lockForUpdate()->findOrFail($subscription->restaurant_id);
            $subscription = RestaurantSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $previousStatus = $subscription->effectiveStatus();

            if ($newStatus === 'active') {
                if ($subscription->status !== 'suspended' || ! $subscription->expires_at->gte(CarbonImmutable::now(config('app.timezone')))) {
                    throw ValidationException::withMessages(['status' => 'Only a suspended, unexpired subscription can be reactivated.']);
                }

                $this->assertNoOverlap($restaurant, $subscription->starts_at, $subscription->expires_at, $subscription->id);
                $action = 'reactivated';
            } else {
                if (! in_array($subscription->status, ['active', 'trial'], true)) {
                    throw ValidationException::withMessages(['status' => 'This subscription can no longer be suspended or cancelled.']);
                }

                $action = $newStatus === 'suspended' ? 'suspended' : 'cancelled';
            }

            $subscription->update(['status' => $newStatus]);
            $this->recordEvent($subscription, $actor, $action, $previousStatus, $newStatus, $eventDetails);
            if ($newStatus === 'active') {
                $this->notifyActivation($subscription);
            }

            return $subscription;
        });
    }

    public function planExpiry(
        ?int $durationMonths,
        ?int $durationDays,
        CarbonImmutable $startsAt,
        ?int $trialDurationDays = null,
        bool $trial = false,
    ): CarbonImmutable {
        $durationDays = $trial ? $trialDurationDays : $durationDays;

        if ($durationDays) {
            return $startsAt->startOfDay()->addDays($durationDays)->subDay()->endOfDay();
        }

        if ($durationMonths) {
            return $startsAt->startOfDay()->addMonthsNoOverflow($durationMonths)->subDay()->endOfDay();
        }

        throw ValidationException::withMessages(['duration' => 'The selected plan does not have a valid duration.']);
    }

    private function assertExpiryAfterStart(CarbonImmutable $startsAt, CarbonImmutable $expiresAt): void
    {
        if ($expiresAt->lt($startsAt->startOfDay())) {
            throw ValidationException::withMessages(['expires_at' => 'The expiry date must be on or after the start date.']);
        }
    }

    private function assertNoOverlap(
        Restaurant $restaurant,
        CarbonImmutable $startsAt,
        CarbonImmutable $expiresAt,
        ?int $exceptId = null,
    ): void {
        $overlap = RestaurantSubscription::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', ['active', 'trial'])
            ->where('starts_at', '<=', $expiresAt)
            ->where('expires_at', '>=', $startsAt->startOfDay())
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['subscription' => 'This restaurant already has an overlapping active or trial subscription.']);
        }
    }

    private function recordEvent(
        RestaurantSubscription $subscription,
        SuperAdmin $actor,
        string $action,
        ?string $previousStatus,
        ?string $newStatus,
        array $details = [],
    ): void {
        RestaurantSubscriptionEvent::create([
            'restaurant_id' => $subscription->restaurant_id,
            'restaurant_subscription_id' => $subscription->id,
            'subscription_plan_id' => $subscription->subscription_plan_id,
            'actor_id' => $actor->id,
            'action' => $action,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'starts_at' => $subscription->starts_at,
            'expires_at' => $subscription->expires_at,
            'details' => $details,
        ]);
    }

    private function notifyActivation(RestaurantSubscription $subscription, bool $renewed = false): void
    {
        $restaurant = Restaurant::query()->findOrFail($subscription->restaurant_id);
        $event = $renewed ? 'subscription_renewed' : 'subscription_activated';
        $this->notifications->businessEvent(
            $event,
            $event.':'.$subscription->id,
            $renewed ? 'Subscription renewed' : 'Subscription activated',
            $restaurant->name.' subscription is '.$subscription->status.' through '.$subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y').'.',
            route('super-admin.subscriptions.show', $subscription),
        );
    }
}
