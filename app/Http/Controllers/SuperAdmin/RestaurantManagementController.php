<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\OwnerPasswordSetupService;
use App\Services\RestaurantApprovalService;
use App\Services\RestaurantProvisioningService;
use App\Services\SuperAdminNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RestaurantManagementController extends Controller
{
    public function __construct(
        protected RestaurantProvisioningService $provisioningService,
        protected RestaurantApprovalService $approvalService,
        protected OwnerPasswordSetupService $passwordSetupService,
        protected SuperAdminNotificationService $notifications,
    ) {}

    public function index(Request $request): View
    {
        $restaurantStats = Restaurant::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END), 0) as suspended")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) as inactive")
            ->first();

        $query = Restaurant::query()->with(['owners' => fn ($owners) => $owners->orderBy('created_at', 'desc')]);

        if ($request->filled('search')) {
            $term = trim($request->input('search'));
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('slug', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if (in_array($status, ['active', 'suspended', 'inactive'], true)) {
                $query->where('status', $status);
            }
        }

        $sortField = in_array($request->input('sort', 'created_at'), ['name', 'email', 'status', 'created_at'], true)
            ? $request->input('sort', 'created_at')
            : 'created_at';
        $sortDirection = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';

        $restaurants = $query->orderBy($sortField, $sortDirection)->paginate(15)->appends($request->query());

        return view('super-admin.restaurants.index', [
            'restaurants' => $restaurants,
            'search' => $request->input('search', ''),
            'statusFilter' => $request->input('status', ''),
            'sort' => $sortField,
            'direction' => $sortDirection,
            'restaurantStats' => $restaurantStats,
        ]);
    }

    public function create(): View
    {
        return view('super-admin.restaurants.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'restaurant.name' => ['required', 'string', 'max:255'],
            'restaurant.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', 'unique:restaurants,slug'],
            'restaurant.email' => ['required', 'email', 'max:255', 'unique:restaurants,email'],
            'restaurant.phone' => ['nullable', 'string', 'max:50'],
            'restaurant.address' => ['nullable', 'string', 'max:500'],
            'restaurant.status' => ['required', 'in:active,suspended,inactive'],
            'restaurant.approval_status' => ['nullable', 'in:pending,approved'],
            'owner.name' => ['required', 'string', 'max:255'],
            'owner.email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);
        $validated['restaurant']['approval_status'] ??= 'approved';

        $actor = Auth::guard('super_admin')->user();
        if (! ($actor instanceof SuperAdmin)) {
            abort(403);
        }

        try {
            [$restaurant, $owner] = DB::transaction(function () use ($validated, $actor): array {
                $result = $this->provisioningService->provision(
                    $validated['restaurant'],
                    $validated['owner'],
                );

                if ($result['restaurant']->approval_status === 'pending') {
                    $this->approvalService->recordInitialPending($result['restaurant'], $actor);
                }

                $this->recordAudit(
                    $actor,
                    'restaurant_created',
                    'restaurant',
                    $result['restaurant']->id,
                    [
                        'restaurant_name' => $result['restaurant']->name,
                        'owner_email' => $result['owner']->email,
                    ],
                );

                return [$result['restaurant'], $result['owner']];
            });
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors())->withInput();
        }

        $setupResult = $this->passwordSetupService->send($owner);
        $this->notifications->restaurantCreated($restaurant, $owner->id);

        $this->recordAudit(
            $actor,
            match ($setupResult['result']) {
                'captured_by_test_transport',
                'accepted_by_transport' => 'owner_setup_link_sent',
                default => 'owner_setup_link_unavailable',
            },
            'restaurant_user',
            $owner->id,
            ['mailer' => config('mail.default'), 'result' => $setupResult['result']],
        );

        return redirect()->route('super-admin.restaurants.show', $restaurant)
            ->with('status', $setupResult['message'])
            ->with('toast_type', $setupResult['toast']);
    }

    public function show(Restaurant $restaurant): View
    {
        $restaurant->load([
            'owners' => fn ($query) => $query->orderBy('created_at', 'desc'),
            'auditLogs' => fn ($query) => $query->with('actor')->limit(10),
        ]);

        return view('super-admin.restaurants.show', [
            'restaurant' => $restaurant,
            'owner' => $restaurant->owners->first(),
        ]);
    }

    public function edit(Restaurant $restaurant): View
    {
        $restaurant->load('owners');

        return view('super-admin.restaurants.edit', [
            'restaurant' => $restaurant,
            'owner' => $restaurant->owners->first(),
        ]);
    }

    public function update(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', Rule::unique('restaurants', 'slug')->ignore($restaurant->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('restaurants', 'email')->ignore($restaurant->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($restaurant, $validated): void {
            $before = $restaurant->only(['name', 'slug', 'email', 'phone', 'address']);
            $restaurant->update($validated);

            $this->recordAudit(
                Auth::guard('super_admin')->user(),
                'restaurant_updated',
                'restaurant',
                $restaurant->id,
                [
                    'before' => $before,
                    'after' => $restaurant->only(['name', 'slug', 'email', 'phone', 'address']),
                ],
            );
        });
        return redirect()->route('super-admin.restaurants.show', $restaurant)
            ->with('status', 'Restaurant details updated successfully.');
    }

    public function toggleStatus(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:active,suspended,inactive'],
        ]);

        $previousStatus = $restaurant->status;
        DB::transaction(function () use ($restaurant, $validated, $previousStatus): void {
            $restaurant->update(['status' => $validated['status']]);

            $action = match ($validated['status']) {
                'active' => 'restaurant_reactivated',
                'suspended' => 'restaurant_suspended',
                default => 'restaurant_marked_inactive',
            };

            $this->recordAudit(
                Auth::guard('super_admin')->user(),
                $action,
                'restaurant',
                $restaurant->id,
                ['from' => $previousStatus, 'to' => $validated['status']],
            );
        });
        if ($previousStatus !== $validated['status']) {
            $this->notifications->businessEvent(
                'restaurant_status',
                'restaurant-status:'.$restaurant->id.':'.$restaurant->updated_at?->timestamp,
                'Restaurant status changed',
                $restaurant->name.' status was changed to '.$validated['status'].'.',
                route('super-admin.restaurants.show', $restaurant),
            );
        }

        $message = match ($validated['status']) {
            'active' => 'Restaurant reactivated successfully.',
            'suspended' => 'Restaurant suspended successfully.',
            default => 'Restaurant marked inactive successfully.',
        };

        return redirect()->route('super-admin.restaurants.show', $restaurant)->with('status', $message);
    }

    public function toggleOwnerStatus(Request $request, Restaurant $restaurant, User $user): RedirectResponse
    {
        $owner = $this->findRestaurantOwner($restaurant, $user);
        $validated = $request->validate([
            'status' => ['required', 'in:active,inactive'],
        ]);

        DB::transaction(function () use ($owner, $validated): void {
            $previousStatus = $owner->status;
            $owner->update(['status' => $validated['status']]);

            $this->recordAudit(
                Auth::guard('super_admin')->user(),
                $validated['status'] === 'active' ? 'owner_enabled' : 'owner_disabled',
                'restaurant_user',
                $owner->id,
                [
                    'restaurant_id' => $owner->restaurant_id,
                    'from' => $previousStatus,
                    'to' => $validated['status'],
                ],
            );
        });

        $message = $validated['status'] === 'active'
            ? 'Owner account enabled successfully.'
            : 'Owner account disabled successfully.';

        return redirect()->route('super-admin.restaurants.show', $restaurant)->with('status', $message);
    }

    public function resetOwnerPassword(Restaurant $restaurant, User $user): RedirectResponse
    {
        $owner = $this->findRestaurantOwner($restaurant, $user);
        $setupResult = $this->passwordSetupService->send($owner);

        DB::transaction(function () use ($owner, $setupResult): void {
            $this->recordAudit(
                Auth::guard('super_admin')->user(),
                match ($setupResult['result']) {
                    'accepted_by_transport' => 'owner_password_reset_requested',
                    'captured_by_test_transport' => 'owner_password_reset_captured',
                    default => 'owner_password_reset_unavailable',
                },
                'restaurant_user',
                $owner->id,
                [
                    'restaurant_id' => $owner->restaurant_id,
                    'mailer' => config('mail.default'),
                    'result' => $setupResult['result'],
                ],
            );
        });

        return redirect()->route('super-admin.restaurants.show', $restaurant)
            ->with('status', $setupResult['message'])
            ->with('toast_type', $setupResult['toast']);
    }

    private function findRestaurantOwner(Restaurant $restaurant, User $user): User
    {
        return $restaurant->users()
            ->whereKey($user->getKey())
            ->where('role', 'owner')
            ->firstOrFail();
    }

    protected function recordAudit(?SuperAdmin $actor, string $action, string $targetType, ?int $targetId, array $details = []): void
    {
        RestaurantAuditLog::create([
            'actor_id' => $actor?->id,
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details,
        ]);
    }
}
