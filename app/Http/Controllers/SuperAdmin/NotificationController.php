<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdminNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(): View
    {
        $adminId = Auth::guard('super_admin')->id();

        return view('super-admin.notifications.index', [
            'notifications' => $this->visibleNotifications($adminId)
                ->orderByDesc('created_at')->paginate(20),
            'unreadCount' => $this->visibleNotifications($adminId)->whereNull('read_at')->count(),
        ]);
    }

    public function feed(): JsonResponse
    {
        $adminId = Auth::guard('super_admin')->id();
        $feedPath = '/'.ltrim((string) parse_url(route('super-admin.notifications.feed'), PHP_URL_PATH), '/');
        $notificationsUrl = route('super-admin.notifications.index');

        return response()->json([
            'unread_count' => $this->visibleNotifications($adminId)->whereNull('read_at')->count(),
            'notifications' => $this->visibleNotifications($adminId)
                ->orderByDesc('created_at')->limit(8)->get(['id', 'category', 'title', 'description', 'url', 'read_at', 'created_at'])
                ->map(function (SuperAdminNotification $notification) use ($feedPath, $notificationsUrl): array {
                    $notificationPath = is_string($notification->url)
                        ? '/'.ltrim((string) parse_url($notification->url, PHP_URL_PATH), '/')
                        : null;

                    return [
                        'id' => $notification->id,
                        'category' => $notification->category,
                        'title' => $notification->title,
                        'description' => $notification->description,
                        'url' => $notificationPath === $feedPath ? $notificationsUrl : $notification->url,
                        'read' => $notification->read_at !== null,
                        'created_at' => $notification->created_at?->diffForHumans(),
                    ];
                }),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function markRead(Request $request, SuperAdminNotification $notification): JsonResponse|RedirectResponse
    {
        abort_unless(
            $notification->super_admin_id === Auth::guard('super_admin')->id()
                && $notification->category !== 'demo_data',
            404,
        );
        $notification->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('super-admin.notifications.index');
    }

    public function markAllRead(): JsonResponse|RedirectResponse
    {
        $this->visibleNotifications(Auth::guard('super_admin')->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        if (request()->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('super-admin.notifications.index');
    }

    private function visibleNotifications(int|string|null $adminId): Builder
    {
        return SuperAdminNotification::query()
            ->where('super_admin_id', $adminId)
            ->where('category', '!=', 'demo_data');
    }
}
