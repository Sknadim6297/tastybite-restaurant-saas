<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use App\Models\RestaurantSubscriptionEvent;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Services\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SubscriptionManagementController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(Request $request): View
    {
        return $this->listing($request, false);
    }

    public function expiring(Request $request): View
    {
        return $this->listing($request, true);
    }

    public function create(): View
    {
        return view('super-admin.subscriptions.create', [
            'restaurants' => Restaurant::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']),
            'plans' => SubscriptionPlan::query()->where('status', 'active')->orderBy('name')->get(),
            'startDate' => CarbonImmutable::now(config('app.timezone'))->format('Y-m-d'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'subscription_plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'trial' => ['sometimes', 'boolean'],
        ]);

        $actor = $this->actor();
        $restaurant = Restaurant::query()->findOrFail($validated['restaurant_id']);
        if (! $restaurant->isActive()) {
            throw ValidationException::withMessages(['restaurant_id' => 'Only an active restaurant can receive a subscription.']);
        }
        $plan = SubscriptionPlan::query()->findOrFail($validated['subscription_plan_id']);
        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d', $validated['starts_at'], config('app.timezone'));

        $subscription = $this->subscriptions->assign(
            $restaurant,
            $plan,
            $startsAt,
            (bool) ($validated['trial'] ?? false),
            $actor,
        );

        return redirect()->route('super-admin.subscriptions.show', $subscription)
            ->with('status', 'Restaurant subscription assigned successfully.');
    }

    public function show(RestaurantSubscription $restaurantSubscription): View
    {
        $restaurantSubscription->load([
            'restaurant',
            'plan',
            'creator',
            'events' => fn ($query) => $query->with('actor')->latest(),
        ]);

        return view('super-admin.subscriptions.show', ['subscription' => $restaurantSubscription]);
    }

    public function renew(Request $request, RestaurantSubscription $restaurantSubscription): RedirectResponse
    {
        $renewal = $this->subscriptions->renew($restaurantSubscription, $this->actor());

        return redirect()->route('super-admin.subscriptions.show', $renewal)
            ->with('status', 'Subscription renewed. The previous subscription remains in history.');
    }

    public function extend(Request $request, RestaurantSubscription $restaurantSubscription): RedirectResponse
    {
        $validated = $request->validate([
            'expiry_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $expiry = CarbonImmutable::createFromFormat('!Y-m-d', $validated['expiry_date'], config('app.timezone'));

        $this->subscriptions->extend($restaurantSubscription, $expiry, $this->actor());

        return redirect()->route('super-admin.subscriptions.show', $restaurantSubscription)
            ->with('status', 'Subscription expiry extended and recorded in history.');
    }

    public function updateStatus(Request $request, RestaurantSubscription $restaurantSubscription): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended', 'cancelled'])],
        ]);

        $this->subscriptions->changeStatus($restaurantSubscription, $validated['status'], $this->actor());

        $message = match ($validated['status']) {
            'active' => 'Subscription reactivated successfully.',
            'suspended' => 'Subscription suspended successfully.',
            'cancelled' => 'Subscription cancelled. Historical records have been retained.',
        };

        return redirect()->route('super-admin.subscriptions.show', $restaurantSubscription)->with('status', $message);
    }

    public function history(Request $request): View
    {
        $query = RestaurantSubscriptionEvent::query()->with(['restaurant', 'actor', 'subscription']);
        $validated = $request->validate([
            'restaurant_id' => ['nullable', 'integer'],
        ]);
        $restaurantId = $validated['restaurant_id'] ?? '';

        if ($restaurantId !== '') {
            $query->where('restaurant_id', $restaurantId);
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($builder) use ($search): void {
                $builder->whereHas('restaurant', fn ($restaurant) => $restaurant
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%"))
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        $events = $query->latest('created_at')->paginate(20)->withQueryString();

        return view('super-admin.subscriptions.history', [
            'events' => $events,
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name']),
            'restaurantId' => $restaurantId,
            'search' => $request->input('search', ''),
        ]);
    }

    private function listing(Request $request, bool $expiring): View
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $databaseNow = $now->utc();
        $query = RestaurantSubscription::query()->with('restaurant');

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($builder) use ($search): void {
                $builder->where('plan_name', 'like', "%{$search}%")
                    ->orWhere('plan_code', 'like', "%{$search}%")
                    ->orWhereHas('restaurant', fn ($restaurant) => $restaurant
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%"));
            });
        }

        if ($expiring) {
            $query->whereIn('status', ['active', 'trial'])
                ->where('starts_at', '<=', $databaseNow)
                ->whereBetween('expires_at', [$databaseNow, $now->addDays(30)->utc()]);
        } elseif (in_array($request->input('status'), ['active', 'trial'], true)) {
            $query->where('status', $request->input('status'))
                ->where('starts_at', '<=', $databaseNow)
                ->where('expires_at', '>=', $databaseNow);
        } elseif ($request->input('status') === 'expired') {
            $query->whereIn('status', ['active', 'trial'])->where('expires_at', '<', $databaseNow);
        } elseif (in_array($request->input('status'), ['suspended', 'cancelled'], true)) {
            $query->where('status', $request->input('status'));
        }

        $sort = in_array($request->input('sort'), ['plan_name', 'starts_at', 'expires_at', 'created_at'], true)
            ? $request->input('sort')
            : 'created_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $subscriptions = $query->orderBy($sort, $direction)->paginate(15)->withQueryString();

        return view('super-admin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'search' => $request->input('search', ''),
            'statusFilter' => $request->input('status', ''),
            'sort' => $sort,
            'direction' => $direction,
            'expiring' => $expiring,
        ]);
    }

    private function actor(): SuperAdmin
    {
        return Auth::guard('super_admin')->user();
    }
}
