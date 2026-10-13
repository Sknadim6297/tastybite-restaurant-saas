<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAuditLog;
use App\Models\SaasFeature;
use App\Models\SubscriptionPlan;
use App\Models\SuperAdmin;
use App\Services\FeatureManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FeatureManagementController extends Controller
{
    public function index(): View
    {
        return view('super-admin.features.index', [
            'features' => SaasFeature::query()
                ->with(['plans' => fn ($query) => $query->orderBy('name')])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'plans' => SubscriptionPlan::query()->orderBy('name')->get(),
            'history' => RestaurantAuditLog::query()
                ->where('action', 'saas_feature_access_updated')
                ->with('actor')
                ->latest('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function update(
        Request $request,
        SaasFeature $feature,
        FeatureManagementService $service,
    ): RedirectResponse {
        if (! $feature->implemented) {
            abort(403, 'This feature is not implemented and cannot be enabled.');
        }

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'plans' => ['required', 'array', 'min:1'],
            'plans.*' => ['required', 'array'],
            'plans.*.enabled' => ['required', 'boolean'],
            'plans.*.limit' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $planSettings = [];
        foreach ($validated['plans'] as $planId => $settings) {
            if (! ctype_digit((string) $planId)) {
                abort(422, 'The subscription plan selection is invalid.');
            }

            $exists = SubscriptionPlan::query()->whereKey((int) $planId)->exists();
            if (! $exists) {
                abort(422, 'The selected subscription plan does not exist.');
            }

            $planSettings[(int) $planId] = [
                'enabled' => (bool) $settings['enabled'],
                'limit' => $settings['limit'] === null || $settings['limit'] === ''
                    ? null
                    : (int) $settings['limit'],
            ];
        }

        $actor = Auth::guard('super_admin')->user();
        if (! ($actor instanceof SuperAdmin)) {
            abort(403);
        }

        $service->update($feature, (bool) $validated['enabled'], $planSettings, $actor);

        return redirect()->route('super-admin.features.index')
            ->with('status', 'Feature access settings updated and audited.');
    }
}
