<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\OwnerPasswordSetupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RestaurantOwnerController extends Controller
{
    public function __construct(
        protected OwnerPasswordSetupService $passwordSetupService,
    ) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(['name', 'email', 'restaurant', 'status', 'created_at', 'last_login_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $stats = User::query()
            ->where('role', 'owner')
            ->whereHas('restaurant', fn ($restaurant) => $restaurant->where('slug', 'not like', 'demo-tb-2026-%'))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) as inactive")
            ->first();

        $query = User::query()
            ->where('users.role', 'owner')
            ->whereHas('restaurant', fn ($restaurant) => $restaurant->where('slug', 'not like', 'demo-tb-2026-%'))
            ->with('restaurant');

        if (! empty($validated['search'])) {
            $term = trim($validated['search']);
            $query->where(function ($owners) use ($term): void {
                $owners->where('users.name', 'like', "%{$term}%")
                    ->orWhere('users.email', 'like', "%{$term}%")
                    ->orWhereHas('restaurant', fn ($restaurant) => $restaurant->where('name', 'like', "%{$term}%"));
            });
        }

        if (! empty($validated['restaurant_id'])) {
            $query->where('users.restaurant_id', $validated['restaurant_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('users.status', $validated['status']);
        }

        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['direction'] ?? 'desc';

        if ($sort === 'restaurant') {
            $query->leftJoin('restaurants as owner_restaurants', 'owner_restaurants.id', '=', 'users.restaurant_id')
                ->select('users.*')
                ->orderBy('owner_restaurants.name', $direction);
        } else {
            $query->orderBy('users.'.$sort, $direction);
        }

        $owners = $query->orderBy('users.id', 'desc')->paginate(15)->withQueryString();

        return view('super-admin.restaurant-owners.index', [
            'owners' => $owners,
            'restaurants' => Restaurant::query()->where('slug', 'not like', 'demo-tb-2026-%')->orderBy('name')->get(['id', 'name']),
            'stats' => $stats,
            'filters' => $validated,
            'sort' => $sort,
            'direction' => $direction,
        ]);
    }

    public function show(User $user): View
    {
        $owner = $this->findOwner($user);
        $owner->load(['restaurant.currentSubscription']);

        $auditLogs = RestaurantAuditLog::query()
            ->where('target_type', 'restaurant_user')
            ->where('target_id', $owner->id)
            ->with('actor')
            ->latest()
            ->limit(25)
            ->get();

        return view('super-admin.restaurant-owners.show', [
            'owner' => $owner,
            'subscription' => $owner->restaurant?->currentSubscription,
            'auditLogs' => $auditLogs,
        ]);
    }

    public function edit(User $user): View
    {
        return view('super-admin.restaurant-owners.edit', [
            'owner' => $this->findOwner($user)->load('restaurant'),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $owner = $this->findOwner($user);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($owner->id)],
            'mobile' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9][0-9\s().-]{5,28}[0-9]$/'],
        ]);

        DB::transaction(function () use ($owner, $validated): void {
            $before = $owner->only(['name', 'email', 'mobile']);
            $owner->fill($validated)->save();

            $this->recordAudit(
                Auth::guard('super_admin')->user(),
                'owner_updated',
                $owner,
                ['before' => $before, 'after' => $owner->only(['name', 'email', 'mobile'])],
            );
        });

        return redirect()->route('super-admin.restaurant-owners.show', $owner)
            ->with('status', 'Owner profile updated successfully.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $owner = $this->findOwner($user);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        if ($owner->status === $validated['status']) {
            return redirect()->route('super-admin.restaurant-owners.show', $owner)
                ->with('status', 'Owner account status is already '.strtolower($validated['status']).'.');
        }

        DB::transaction(function () use ($owner, $validated): void {
            $previousStatus = $owner->status;
            $owner->update(['status' => $validated['status']]);

            $this->recordAudit(
                Auth::guard('super_admin')->user(),
                $validated['status'] === 'active' ? 'owner_enabled' : 'owner_disabled',
                $owner,
                ['restaurant_id' => $owner->restaurant_id, 'from' => $previousStatus, 'to' => $validated['status']],
            );
        });

        return redirect()->route('super-admin.restaurant-owners.show', $owner)
            ->with('status', $validated['status'] === 'active'
                ? 'Owner account enabled successfully.'
                : 'Owner account disabled. Existing sessions will be required to sign in again.');
    }

    public function sendPasswordResetLink(User $user): RedirectResponse
    {
        $owner = $this->findOwner($user);
        $actor = Auth::guard('super_admin')->user();
        $result = $this->passwordSetupService->send($owner);
        $this->recordPasswordResetAudit($actor, $owner, $result['result']);

        return redirect()->route('super-admin.restaurant-owners.show', $owner)
            ->with('status', $result['message'])
            ->with('toast_type', $result['toast']);
    }

    private function findOwner(User $user): User
    {
        abort_unless($user->role === 'owner', 404);
        abort_if(str_starts_with((string) $user->restaurant?->slug, 'demo-tb-2026-'), 404);

        return $user;
    }

    private function recordPasswordResetAudit(?SuperAdmin $actor, User $owner, string $result): void
    {
        $action = match ($result) {
            'accepted_by_transport' => 'owner_password_reset_requested',
            'captured_by_test_transport' => 'owner_password_reset_captured',
            default => 'owner_password_reset_unavailable',
        };

        $this->recordAudit($actor, $action, $owner, [
            'restaurant_id' => $owner->restaurant_id,
            'mailer' => config('mail.default'),
            'result' => $result,
        ]);
    }

    private function recordAudit(?SuperAdmin $actor, string $action, User $owner, array $details): void
    {
        RestaurantAuditLog::create([
            'actor_id' => $actor?->id,
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => 'restaurant_user',
            'target_id' => $owner->id,
            'details' => $details,
        ]);
    }
}
