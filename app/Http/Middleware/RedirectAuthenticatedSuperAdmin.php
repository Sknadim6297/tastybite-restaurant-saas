<?php

namespace App\Http\Middleware;

use App\Models\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectAuthenticatedSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('super_admin');
        $superAdmin = $guard->user();
        $sessionPasswordVersion = $request->session()->get('super_admin_password_version');

        if (
            $superAdmin instanceof SuperAdmin
            && $superAdmin->is_active
            && is_string($sessionPasswordVersion)
            && hash_equals(hash('sha256', $superAdmin->getAuthPassword()), $sessionPasswordVersion)
        ) {
            return redirect()->route('super-admin.dashboard');
        }

        if ($superAdmin) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $next($request);
    }
}
