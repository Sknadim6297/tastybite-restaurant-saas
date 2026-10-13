<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Models\SupportAccessRequest;
use App\Services\SupportAccessRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportAccessController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'denied', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:150'],
        ]);
        $query = SupportAccessRequest::query()->with(['restaurant', 'requester', 'reviewer']);
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($builder) => $builder
                ->where('requested_scope', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%")
                ->orWhereHas('restaurant', fn ($restaurant) => $restaurant->where('name', 'like', "%{$search}%"))
                ->orWhereHas('requester', fn ($admin) => $admin->where('name', 'like', "%{$search}%")));
        }

        return view('super-admin.support-access.index', [
            'requests' => $query->orderByDesc('created_at')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'pendingCount' => SupportAccessRequest::query()->where('status', 'pending')->count(),
        ]);
    }

    public function create(): View
    {
        return view('super-admin.support-access.create', [
            'restaurants' => Restaurant::query()->orderBy('name')->get(['id', 'name', 'slug', 'status']),
        ]);
    }

    public function store(Request $request, SupportAccessRequestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['required', 'integer', 'exists:restaurants,id'],
            'requested_scope' => ['required', Rule::in(['billing', 'technical', 'account', 'other'])],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'requested_duration_minutes' => ['required', 'integer', 'between:30,480'],
        ]);
        $supportRequest = $service->create($this->actor(), $validated);

        return redirect()->route('super-admin.support-access.show', $supportRequest)
            ->with('status', 'Support-access request submitted for independent review. No restaurant access has been granted.');
    }

    public function show(SupportAccessRequest $supportAccessRequest): View
    {
        $supportAccessRequest->load([
            'restaurant',
            'requester',
            'reviewer',
            'events.actor',
        ]);

        return view('super-admin.support-access.show', [
            'supportRequest' => $supportAccessRequest,
            'currentAdmin' => $this->actor(),
        ]);
    }

    public function decide(Request $request, SupportAccessRequest $supportAccessRequest, SupportAccessRequestService $service): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'denied'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $service->decide($supportAccessRequest, $this->actor(), $validated['decision'], $validated['reason']);

        return redirect()->route('super-admin.support-access.show', $supportAccessRequest)
            ->with('status', 'Decision recorded. Approval does not open or impersonate a restaurant account.');
    }

    public function cancel(Request $request, SupportAccessRequest $supportAccessRequest, SupportAccessRequestService $service): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $service->cancel($supportAccessRequest, $this->actor(), $validated['reason'] ?? null);

        return redirect()->route('super-admin.support-access.show', $supportAccessRequest)
            ->with('status', 'Pending support-access request cancelled.');
    }

    private function actor(): SuperAdmin
    {
        $actor = Auth::guard('super_admin')->user();
        if (! ($actor instanceof SuperAdmin)) {
            abort(403);
        }

        return $actor;
    }
}
