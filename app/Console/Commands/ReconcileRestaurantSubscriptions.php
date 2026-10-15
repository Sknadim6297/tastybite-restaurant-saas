<?php

namespace App\Console\Commands;

use App\Models\RestaurantSubscription;
use App\Models\RestaurantSubscriptionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileRestaurantSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile';

    protected $description = 'Activate due scheduled subscriptions and mark expired subscription periods';

    public function handle(): int
    {
        $now = CarbonImmutable::now(config('app.timezone'))->utc();
        $updated = 0;

        RestaurantSubscription::query()
            ->where('status', 'scheduled')
            ->where('starts_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($now, &$updated): void {
                DB::transaction(function () use ($id, $now, &$updated): void {
                    $subscription = RestaurantSubscription::query()->lockForUpdate()->find($id);
                    if (! $subscription || $subscription->status !== 'scheduled' || $subscription->starts_at->gt($now)) {
                        return;
                    }

                    $previousStatus = $subscription->status;
                    $newStatus = $subscription->expires_at->lt($now)
                        ? 'expired'
                        : ($subscription->is_trial ? 'trial' : 'active');
                    $subscription->update(['status' => $newStatus]);
                    RestaurantSubscriptionEvent::create([
                        'restaurant_id' => $subscription->restaurant_id,
                        'restaurant_subscription_id' => $subscription->id,
                        'subscription_plan_id' => $subscription->subscription_plan_id,
                        'actor_id' => null,
                        'action' => $newStatus === 'expired' ? 'scheduled_period_expired' : 'scheduled_period_activated',
                        'previous_status' => $previousStatus,
                        'new_status' => $newStatus,
                        'starts_at' => $subscription->starts_at,
                        'expires_at' => $subscription->expires_at,
                    ]);
                    $updated++;
                });
            });

        RestaurantSubscription::query()
            ->whereIn('status', ['active', 'trial'])
            ->where('expires_at', '<', $now)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use ($now, &$updated): void {
                DB::transaction(function () use ($id, $now, &$updated): void {
                    $subscription = RestaurantSubscription::query()->lockForUpdate()->find($id);
                    if (
                        ! $subscription
                        || ! in_array($subscription->status, ['active', 'trial'], true)
                        || $subscription->expires_at->gte($now)
                    ) {
                        return;
                    }

                    $previousStatus = $subscription->status;
                    $subscription->update(['status' => 'expired']);
                    RestaurantSubscriptionEvent::create([
                        'restaurant_id' => $subscription->restaurant_id,
                        'restaurant_subscription_id' => $subscription->id,
                        'subscription_plan_id' => $subscription->subscription_plan_id,
                        'actor_id' => null,
                        'action' => 'period_expired',
                        'previous_status' => $previousStatus,
                        'new_status' => 'expired',
                        'starts_at' => $subscription->starts_at,
                        'expires_at' => $subscription->expires_at,
                    ]);
                    $updated++;
                });
            });

        $this->info("Reconciled {$updated} subscription period(s).");

        return self::SUCCESS;
    }
}
