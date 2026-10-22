<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Services\RestaurantDashboardAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestaurantDashboardAccessController extends Controller
{
    public function enter(
        Restaurant $restaurant,
        Request $request,
        RestaurantDashboardAccessService $service,
    ): RedirectResponse {
        $service->start($restaurant, $this->actor(), $request);

        return redirect()->route('restaurant.dashboard');
    }

    public function returnToSuperAdmin(
        Request $request,
        RestaurantDashboardAccessService $service,
    ): RedirectResponse {
        $service->end($request, $this->actor());

        return redirect()->route('super-admin.restaurants.index')
            ->with('status', 'Restaurant access ended. Your Super Admin session remains active.');
    }

    private function actor(): SuperAdmin
    {
        $actor = Auth::guard('super_admin')->user();
        abort_unless($actor instanceof SuperAdmin, 403);

        return $actor;
    }
}
