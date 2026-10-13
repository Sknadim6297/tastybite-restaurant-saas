<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\SuperAdmin;
use App\Models\SuperAdminNotification;
use App\Models\SubscriptionPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class SuperAdminNotificationService
{
    public function __construct(private readonly PlatformSettingsService $settings) {}

    public function businessEvent(
        string $category,
        string $eventKey,
        string $title,
        string $description,
        ?string $url = null,
    ): int {
        $settings = $this->settings->group('notifications');
        if (! $settings['enabled'] || ! $this->categoryEnabled($category, $settings)) {
            return 0;
        }

        return DB::transaction(function () use ($category, $eventKey, $title, $description, $url): int {
            $created = 0;
            foreach (SuperAdmin::query()->where('is_active', true)->cursor() as $admin) {
                try {
                    $notification = SuperAdminNotification::query()->firstOrCreate(
                        ['super_admin_id' => $admin->id, 'event_key' => $eventKey],
                        [
                            'category' => $category,
                            'title' => $title,
                            'description' => $description,
                            'url' => $url,
                        ],
                    );
                    $created += $notification->wasRecentlyCreated ? 1 : 0;
                } catch (QueryException $exception) {
                    $duplicate = SuperAdminNotification::query()
                        ->where('super_admin_id', $admin->id)
                        ->where('event_key', $eventKey)
                        ->exists();
                    if (! $duplicate) {
                        throw $exception;
                    }
                }
            }

            return $created;
        });
    }

    public function subscriptionExpiryAlerts(): int
    {
        $settings = $this->settings->group('subscription');
        $notificationSettings = $this->settings->group('notifications');
        if (! $settings['expiry_alerts_enabled'] || ! $notificationSettings['expiry_alerts']) {
            return 0;
        }

        $now = CarbonImmutable::now(config('app.timezone'));
        $created = 0;
        $subscriptions = RestaurantSubscription::query()
            ->with('restaurant')
            ->whereIn('status', ['active', 'trial'])
            ->where('starts_at', '<=', $now->utc())
            ->where('expires_at', '<=', $now->addDays(max([1, ...$settings['expiry_reminder_days']]))->endOfDay()->utc())
            ->orderBy('id')
            ->cursor();

        foreach ($subscriptions as $subscription) {
            if ($subscription->expires_at->lt($now)) {
                $created += $this->businessEvent(
                    'subscription_expired',
                    'subscription-expired:'.$subscription->id.':'.$subscription->expires_at->timestamp,
                    'Subscription expired',
                    $subscription->restaurant->name.' subscription expired on '.$subscription->expires_at->timezone(config('app.timezone'))->format('M j, Y').'.',
                    route('super-admin.subscriptions.show', $subscription),
                );
                continue;
            }

            $days = $now->startOfDay()->diffInDays($subscription->expires_at->timezone(config('app.timezone'))->startOfDay());
            foreach ($settings['expiry_reminder_days'] as $threshold) {
                if ($days !== (int) $threshold) {
                    continue;
                }

                $key = 'subscription-expiring:'.$subscription->id.':'.$subscription->expires_at->timestamp.':'.$threshold;
                $created += $this->businessEvent(
                    'subscription_expiring',
                    $key,
                    'Subscription expiring soon',
                    $subscription->restaurant->name.' subscription expires in '.$threshold.' day'.($threshold === 1 ? '' : 's').'.',
                    route('super-admin.subscriptions.show', $subscription),
                );
            }
        }

        return $created;
    }

    public function restaurantCreated(Restaurant $restaurant, int $ownerId): void
    {
        $this->businessEvent(
            'restaurant_registered',
            'restaurant-created:'.$restaurant->id,
            'New restaurant registered',
            $restaurant->name.' was added to the platform.',
            route('super-admin.restaurants.show', $restaurant),
        );
        $this->businessEvent(
            'owner_created',
            'restaurant-owner-created:'.$ownerId,
            'Restaurant owner created',
            'An owner account was created for '.$restaurant->name.'.',
            route('super-admin.restaurant-owners.show', $ownerId),
        );
    }

    public function paymentEvent(string $event, SubscriptionPayment $payment): void
    {
        $restaurant = $payment->restaurant;
        $labels = [
            'payment_submitted' => ['Payment submitted', 'A manual payment was submitted for review.'],
            'payment_approved' => ['Payment approved', 'A manual payment was approved.'],
            'payment_rejected' => ['Payment rejected', 'A manual payment was rejected.'],
            'refund_recorded' => ['Refund recorded', 'A manual refund record was added.'],
        ];
        [$title, $description] = $labels[$event];
        $this->businessEvent(
            $event,
            $event.':'.$payment->id.($event === 'refund_recorded' ? ':'.($payment->refunds()->latest('id')->value('id') ?? 0) : ''),
            $title,
            $restaurant->name.': '.$description,
            route('super-admin.payments.show', $payment),
        );
    }

    private function categoryEnabled(string $category, array $settings): bool
    {
        if (str_starts_with($category, 'payment_') || $category === 'refund_recorded') {
            return (bool) $settings['payment_alerts'];
        }

        if (in_array($category, ['restaurant_registered', 'owner_created', 'restaurant_status'], true)) {
            return (bool) $settings['restaurant_alerts'];
        }

        if (in_array($category, ['subscription_expiring', 'subscription_expired', 'subscription_activated', 'subscription_renewed'], true)) {
            return (bool) $settings['expiry_alerts'];
        }

        return true;
    }
}
