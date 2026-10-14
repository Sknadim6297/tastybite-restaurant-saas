<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAuditLog;
use App\Models\SubscriptionPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubscriptionPlanController extends Controller
{
    public function index(Request $request): View
    {
        $query = SubscriptionPlan::query()->withCount('subscriptions');

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('status', $request->input('status'));
        }

        $plans = $query->orderBy('name')->paginate(15)->withQueryString();

        return view('super-admin.subscription-plans.index', [
            'plans' => $plans,
            'search' => $request->input('search', ''),
            'statusFilter' => $request->input('status', ''),
        ]);
    }

    public function create(): View
    {
        return view('super-admin.subscription-plans.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedPlan($request);
        $plan = DB::transaction(function () use ($data): SubscriptionPlan {
            $plan = SubscriptionPlan::create($this->planAttributes($data));
            $this->recordPlanAudit('subscription_plan_created', $plan);

            return $plan;
        });

        return redirect()->route('super-admin.subscription-plans.show', $plan)
            ->with('status', 'Subscription plan created successfully.');
    }

    public function show(SubscriptionPlan $subscriptionPlan): View
    {
        $subscriptionPlan->loadCount('subscriptions');
        $subscriptionPlan->load([
            'subscriptions' => fn ($query) => $query->with('restaurant')->latest()->limit(10),
        ]);

        return view('super-admin.subscription-plans.show', ['plan' => $subscriptionPlan]);
    }

    public function edit(SubscriptionPlan $subscriptionPlan): View
    {
        return view('super-admin.subscription-plans.edit', ['plan' => $subscriptionPlan]);
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $data = $this->validatedPlan($request, $subscriptionPlan);
        DB::transaction(function () use ($data, $subscriptionPlan): void {
            $before = $subscriptionPlan->only([
                'name', 'code', 'description', 'price', 'billing_period',
                'duration_months', 'duration_days', 'trial_available', 'trial_duration_days', 'status',
            ]);
            $subscriptionPlan->update($this->planAttributes($data));

            $this->recordPlanAudit('subscription_plan_updated', $subscriptionPlan, [
                'before' => $before,
                'after' => $subscriptionPlan->only(array_keys($before)),
            ]);
        });

        return redirect()->route('super-admin.subscription-plans.show', $subscriptionPlan)
            ->with('status', 'Subscription plan updated. Existing subscriptions retain their saved plan terms.');
    }

    public function updateStatus(Request $request, SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        DB::transaction(function () use ($subscriptionPlan, $validated): void {
            $previousStatus = $subscriptionPlan->status;
            $subscriptionPlan->update(['status' => $validated['status']]);

            $this->recordPlanAudit(
                $validated['status'] === 'active' ? 'subscription_plan_activated' : 'subscription_plan_deactivated',
                $subscriptionPlan,
                ['from' => $previousStatus, 'to' => $validated['status']],
            );
        });

        $message = $validated['status'] === 'active'
            ? 'Subscription plan activated successfully.'
            : 'Subscription plan deactivated. Existing subscriptions are unaffected.';

        return redirect()->route('super-admin.subscription-plans.show', $subscriptionPlan)->with('status', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPlan(Request $request, ?SubscriptionPlan $plan = null): array
    {
        $input = $request->all();
        if (isset($input['code']) && is_string($input['code'])) {
            $input['code'] = strtoupper(trim($input['code']));
        }

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required',
                'string',
                'max:40',
                'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
                Rule::unique('subscription_plans', 'code')->ignore($plan?->id),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'billing_period' => ['required', Rule::in(['monthly', 'quarterly', 'yearly', 'custom'])],
            'duration_months' => [Rule::excludeIf(($input['billing_period'] ?? null) !== 'custom'), 'nullable', 'integer', 'min:1', 'max:120'],
            'duration_days' => [Rule::excludeIf(($input['billing_period'] ?? null) !== 'custom'), 'nullable', 'integer', 'min:1', 'max:3650'],
            'trial_available' => ['required', 'boolean'],
            'trial_duration_days' => [Rule::excludeIf(! filter_var($input['trial_available'] ?? false, FILTER_VALIDATE_BOOLEAN)), 'nullable', 'integer', 'min:1', 'max:365'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $validator->after(function ($validator) use ($input): void {
            if (($input['billing_period'] ?? null) === 'custom') {
                $hasMonths = filled($input['duration_months'] ?? null);
                $hasDays = filled($input['duration_days'] ?? null);

                if ($hasMonths === $hasDays) {
                    $validator->errors()->add('duration_months', 'Choose exactly one custom duration: months or days.');
                }
            }

            if (filter_var($input['trial_available'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && blank($input['trial_duration_days'] ?? null)) {
                $validator->errors()->add('trial_duration_days', 'Enter the number of trial days.');
            }
        });

        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function planAttributes(array $data): array
    {
        $periodMonths = match ($data['billing_period']) {
            'monthly' => 1,
            'quarterly' => 3,
            'yearly' => 12,
            default => null,
        };
        $trialAvailable = (bool) $data['trial_available'];

        return [
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'currency' => 'INR',
            'billing_period' => $data['billing_period'],
            'duration_months' => $periodMonths ?? ($data['duration_months'] ?? null),
            'duration_days' => $periodMonths === null ? ($data['duration_days'] ?? null) : null,
            'trial_available' => $trialAvailable,
            'trial_duration_days' => $trialAvailable ? ($data['trial_duration_days'] ?? null) : null,
            'status' => $data['status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function recordPlanAudit(string $action, SubscriptionPlan $plan, array $details = []): void
    {
        RestaurantAuditLog::create([
            'actor_id' => Auth::guard('super_admin')->id(),
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => 'subscription_plan',
            'target_id' => $plan->id,
            'details' => ['plan_code' => $plan->code, 'plan_name' => $plan->name] + $details,
        ]);
    }
}
