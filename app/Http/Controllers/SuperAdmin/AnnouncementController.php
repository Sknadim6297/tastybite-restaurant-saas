<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAnnouncement;
use App\Models\Restaurant;
use App\Models\SuperAdmin;
use App\Services\PlatformAnnouncementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'search' => ['nullable', 'string', 'max:180'],
        ]);
        $query = $this->businessAnnouncements()->with('author')->withCount(['restaurants', 'reads']);

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['search'] ?? null)) {
            $search = trim($filters['search']);
            $query->where(fn ($builder) => $builder
                ->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%"));
        }

        return view('super-admin.announcements.index', [
            'announcements' => $query->orderByDesc('created_at')->paginate(15)->withQueryString(),
            'filters' => $filters,
            'counts' => [
                'draft' => $this->businessAnnouncements()->where('status', 'draft')->count(),
                'published' => $this->businessAnnouncements()->where('status', 'published')->count(),
                'archived' => $this->businessAnnouncements()->where('status', 'archived')->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('super-admin.announcements.create', [
            'restaurants' => $this->eligibleRestaurants(),
        ]);
    }

    public function store(Request $request, PlatformAnnouncementService $service): RedirectResponse
    {
        $actor = $this->actor();
        $validated = $this->validateAnnouncement($request);
        $announcement = $service->createDraft(
            $actor,
            $validated,
            $validated['restaurant_ids'] ?? [],
        );

        return redirect()->route('super-admin.announcements.show', $announcement)
            ->with('status', 'Announcement draft created. No email was sent.');
    }

    public function show(PlatformAnnouncement $announcement): View
    {
        $this->ensureBusinessAnnouncement($announcement);
        $announcement->load(['author', 'restaurants' => fn ($query) => $query->orderBy('name')]);
        $recipientCount = $announcement->audience === 'all'
            ? $this->eligibleRestaurants()->count()
            : $announcement->restaurants()->where('status', 'active')
                ->whereHas('owners', fn ($query) => $query->where('status', 'active'))->count();

        return view('super-admin.announcements.show', [
            'announcement' => $announcement,
            'restaurants' => $this->eligibleRestaurants(),
            'recipientCount' => $recipientCount,
            'readCount' => $announcement->reads()->count(),
        ]);
    }

    public function update(Request $request, PlatformAnnouncement $announcement, PlatformAnnouncementService $service): RedirectResponse
    {
        $this->ensureBusinessAnnouncement($announcement);
        $validated = $this->validateAnnouncement($request);
        $service->updateDraft(
            $announcement,
            $this->actor(),
            $validated,
            $validated['restaurant_ids'] ?? [],
        );

        return redirect()->route('super-admin.announcements.show', $announcement)
            ->with('status', 'Announcement draft updated.');
    }

    public function publish(PlatformAnnouncement $announcement, PlatformAnnouncementService $service): RedirectResponse
    {
        $this->ensureBusinessAnnouncement($announcement);
        $service->publish($announcement, $this->actor());

        return redirect()->route('super-admin.announcements.show', $announcement)
            ->with('status', 'Announcement published to the selected in-app audience. No email was sent.');
    }

    public function archive(PlatformAnnouncement $announcement, PlatformAnnouncementService $service): RedirectResponse
    {
        $this->ensureBusinessAnnouncement($announcement);
        $service->archive($announcement, $this->actor());

        return redirect()->route('super-admin.announcements.show', $announcement)
            ->with('status', 'Announcement archived and removed from restaurant owner access.');
    }

    private function validateAnnouncement(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:10000'],
            'audience' => ['required', Rule::in(['all', 'selected'])],
            'restaurant_ids' => ['required_if:audience,selected', 'array', 'min:1', 'max:500'],
            'restaurant_ids.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('restaurants', 'id')->where(fn ($query) => $query
                    ->where('status', 'active')->where('slug', 'not like', 'demo-tb-2026-%')),
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
    }

    private function eligibleRestaurants(): Collection
    {
        return Restaurant::query()->where('status', 'active')
            ->where('slug', 'not like', 'demo-tb-2026-%')
            ->whereHas('owners', fn ($query) => $query->where('status', 'active'))
            ->orderBy('name')->get(['id', 'name', 'slug']);
    }

    private function businessAnnouncements(): Builder
    {
        return PlatformAnnouncement::query()->whereRaw('substr(title, 1, 14) != ?', ['[DEMO-TB-2026]']);
    }

    private function ensureBusinessAnnouncement(PlatformAnnouncement $announcement): void
    {
        abort_if(str_starts_with($announcement->title, '[DEMO-TB-2026]'), 404);
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
