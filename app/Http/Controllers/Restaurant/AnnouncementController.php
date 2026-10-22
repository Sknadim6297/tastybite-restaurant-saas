<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\PlatformAnnouncement;
use App\Models\User;
use App\Services\RestaurantAccessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        $owner = $this->owner();
        $announcements = $this->visibleQuery($owner)->with([
            'reads' => fn ($query) => $query->where('restaurant_id', $owner->restaurant_id),
        ])->orderByDesc('published_at')->paginate(15);

        return view('restaurant.announcements.index', [
            'announcements' => $announcements,
            'user' => $owner,
            'restaurant' => $owner->restaurant,
        ]);
    }

    public function show(PlatformAnnouncement $announcement): View
    {
        $owner = $this->owner();
        $announcement = $this->visibleQuery($owner)->whereKey($announcement->id)->firstOrFail();
        $announcement->load([
            'author',
            'reads' => fn ($query) => $query->where('restaurant_id', $owner->restaurant_id),
        ]);

        return view('restaurant.announcements.show', [
            'announcement' => $announcement,
            'user' => $owner,
            'restaurant' => $owner->restaurant,
        ]);
    }

    public function markRead(PlatformAnnouncement $announcement): RedirectResponse
    {
        $owner = $this->owner();
        $announcement = $this->visibleQuery($owner)->whereKey($announcement->id)->firstOrFail();
        DB::table('restaurant_announcement_reads')->insertOrIgnore([
            'platform_announcement_id' => $announcement->id,
            'restaurant_id' => $owner->restaurant_id,
            'read_at' => now(),
        ]);

        return redirect()->route('restaurant.announcements.show', $announcement)
            ->with('status', 'Announcement marked as read.');
    }

    /** @return Builder<PlatformAnnouncement> */
    private function visibleQuery(User $owner): Builder
    {
        return PlatformAnnouncement::query()
            ->whereRaw('substr(title, 1, 14) != ?', ['[DEMO-TB-2026]'])
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->where('audience', 'all')
                ->orWhereHas('restaurants', fn ($restaurants) => $restaurants->whereKey($owner->restaurant_id)));
    }

    private function owner(): User
    {
        return app(RestaurantAccessContext::class)->owner();
    }
}
