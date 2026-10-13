<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SecurityCenterController extends Controller
{
    private const EVENTS = [
        'super_admin_login',
        'super_admin_logout',
        'super_admin_login_failed',
        'super_admin_password_reset',
        'super_admin_password_changed',
        'owner_login_success',
        'restaurant_login_failed',
    ];

    public function index(Request $request): View
    {
        $since = CarbonImmutable::now(config('app.timezone'))->subDay()->utc();
        $recentAttempts = RestaurantAuditLog::query()
            ->whereIn('action', ['super_admin_login_failed', 'restaurant_login_failed'])
            ->where('created_at', '>=', $since);
        $secureCookie = (bool) config('session.secure') || $request->isSecure();

        return view('super-admin.security-center.index', [
            'admins' => SuperAdmin::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'email', 'is_active']),
            'adminCounts' => [
                'active' => SuperAdmin::query()->where('is_active', true)->count(),
                'inactive' => SuperAdmin::query()->where('is_active', false)->count(),
            ],
            'activity' => [
                'failed_logins' => (clone $recentAttempts)->count(),
                'failed_admin_logins' => (clone $recentAttempts)->where('action', 'super_admin_login_failed')->count(),
                'successful_admin_logins' => RestaurantAuditLog::query()->where('action', 'super_admin_login')
                    ->where('created_at', '>=', $since)->count(),
                'successful_owner_logins' => RestaurantAuditLog::query()->where('action', 'owner_login_success')
                    ->where('created_at', '>=', $since)->count(),
            ],
            'events' => RestaurantAuditLog::query()->whereIn('action', self::EVENTS)
                ->with('actor')->orderByDesc('created_at')->orderByDesc('id')->limit(30)->get(),
            'session' => [
                'driver' => (string) config('session.driver'),
                'lifetime_minutes' => (int) config('session.lifetime'),
                'expires_on_close' => (bool) config('session.expire_on_close'),
                'secure_cookie' => $secureCookie,
                'http_only' => (bool) config('session.http_only'),
                'same_site' => (string) config('session.same_site'),
            ],
            'resetTokenTtl' => (int) config('auth.passwords.super_admins.expire'),
            'resetThrottleSeconds' => (int) config('auth.passwords.super_admins.throttle'),
            'loginLimit' => \App\Http\Controllers\SuperAdmin\AuthController::LOGIN_ATTEMPTS_PER_MINUTE,
            'mailDriver' => (string) config('mail.default'),
            'mailStatus' => config('mail.default') === 'log'
                ? 'Log transport; messages are not delivered to recipients.'
                : 'Transport is configured by Laravel; successful delivery has not been verified here.',
            'databaseDriver' => DB::connection()->getDriverName(),
        ]);
    }
}
