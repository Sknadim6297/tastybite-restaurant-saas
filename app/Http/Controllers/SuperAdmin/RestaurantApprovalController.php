<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Services\RestaurantApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RestaurantApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', Rule::in(['pending', 'information_requested', 'approved', 'rejected'])],
        ]);
        $query = Restaurant::query()->with('owners')->withCount('approvalHistory');

        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('owners', fn ($owner) => $owner->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")));
        }

        $status = $filters['status'] ?? null;
        if ($status) {
            $query->where('approval_status', $status);
        } else {
            $query->whereIn('approval_status', ['pending', 'information_requested']);
        }

        return view('super-admin.restaurant-approvals.index', [
            'restaurants' => $query->orderBy('created_at')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'pendingCount' => Restaurant::query()->where('approval_status', 'pending')->count(),
            'informationCount' => Restaurant::query()->where('approval_status', 'information_requested')->count(),
        ]);
    }

    public function show(Restaurant $restaurant): View
    {
        $restaurant->load([
            'owners' => fn ($query) => $query->orderBy('created_at'),
            'approvalHistory.actor',
        ]);

        return view('super-admin.restaurant-approvals.show', ['restaurant' => $restaurant]);
    }

    public function decide(Request $request, Restaurant $restaurant, RestaurantApprovalService $service): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'information_requested', 'pending'])],
            'reason' => ['nullable', 'string', 'max:2000', 'required_if:decision,rejected,information_requested,pending'],
        ]);
        $actor = Auth::guard('super_admin')->user();
        if (! ($actor instanceof SuperAdmin)) {
            abort(403);
        }

        $service->decide($restaurant, $actor, $validated['decision'], $validated['reason'] ?? null);

        $message = match ($validated['decision']) {
            'approved' => 'Restaurant approved. Its operational routes are now available subject to subscription.',
            'rejected' => 'Restaurant approval rejected. Its operational routes remain unavailable.',
            'information_requested' => 'More information requested. The note is visible to the restaurant owner.',
            default => 'Restaurant returned to the approval queue.',
        };

        return redirect()->route('super-admin.restaurant-approvals.show', $restaurant)
            ->with('status', $message);
    }
}
