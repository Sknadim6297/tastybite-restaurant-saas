<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Models\SupportAccessRequest;
use App\Services\RestaurantSupportAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestaurantSupportAccessController extends Controller
{
    public function start(
        Restaurant $restaurant,
        Request $request,
        RestaurantSupportAccessService $service,
    ): RedirectResponse {
        $admin = $this->actor();
        $service->startDirect($restaurant, $admin, $request);

        return redirect()->route('restaurant.dashboard');
    }

    public function end(Request $request, RestaurantSupportAccessService $service): RedirectResponse
    {
        $admin = $this->actor();
        if ($request->session()->has(RestaurantSupportAccessService::SESSION_ADMIN_ACCESS_RESTAURANT_ID)) {
            $service->endDirect($request, $admin);
        } else {
            $service->end($request, $admin);
        }

        return redirect()->route('super-admin.restaurants.index')
            ->with('status', 'Restaurant access ended. Your Super Admin session remains active.');
    }

    public function revoke(
        SupportAccessRequest $supportAccessRequest,
        Request $request,
        RestaurantSupportAccessService $service,
    ): RedirectResponse {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $service->revoke($supportAccessRequest, $this->actor(), $validated['reason'] ?? null);

        return redirect()->route('super-admin.support-access.show', $supportAccessRequest)
            ->with('status', 'The restaurant support session has been revoked.');
    }

    private function actor(): SuperAdmin
    {
        $actor = Auth::guard('super_admin')->user();
        abort_unless($actor instanceof SuperAdmin, 403);

        return $actor;
    }
}
