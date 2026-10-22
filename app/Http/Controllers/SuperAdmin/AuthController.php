<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Services\LoginSecurityAuditService;
use App\Services\RestaurantSupportAccessService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class AuthController extends Controller
{
    public const LOGIN_ATTEMPTS_PER_MINUTE = 5;

    public function showLogin(): View
    {
        return view('super-admin.auth.login');
    }

    public function login(Request $request, LoginSecurityAuditService $securityAudit): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('super-admin.login')
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }

        $credentials = $validator->validated();
        $credentials['email'] = Str::lower($credentials['email']);

        if (! Auth::guard('super_admin')->attempt($credentials)) {
            $securityAudit->recordFailure('super_admin', $credentials['email']);

            return redirect()->route('super-admin.login')
                ->withErrors(['email' => 'These credentials do not match our records.'])
                ->withInput($request->only('email'));
        }

        $request->session()->regenerate();
        $request->session()->put(
            'super_admin_password_version',
            hash('sha256', Auth::guard('super_admin')->user()->getAuthPassword())
        );
        RestaurantAuditLog::create([
            'actor_id' => Auth::guard('super_admin')->id(),
            'actor_type' => 'super_admin',
            'action' => 'super_admin_login',
            'target_type' => 'super_admin',
            'target_id' => Auth::guard('super_admin')->id(),
            'details' => [],
        ]);

        return redirect()->route('super-admin.dashboard')
            ->with('status', 'Welcome back.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $admin = Auth::guard('super_admin')->user();
        if ($admin instanceof SuperAdmin && $request->session()->has(RestaurantSupportAccessService::SESSION_REQUEST_ID)) {
            app(RestaurantSupportAccessService::class)->end($request, $admin);
        }
        if ($admin instanceof SuperAdmin) {
            app(RestaurantSupportAccessService::class)->clearDirectAccessForLogout($request, $admin);
        }
        if ($admin !== null) {
            RestaurantAuditLog::create([
                'actor_id' => $admin->id,
                'actor_type' => 'super_admin',
                'action' => 'super_admin_logout',
                'target_type' => 'super_admin',
                'target_id' => $admin->id,
                'details' => [],
            ]);
        }
        Auth::guard('super_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('super-admin.login')
            ->with('status', 'You have been signed out.');
    }

    public function showForgotPassword(): View
    {
        return view('super-admin.auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('super-admin.password.request')
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }

        if (config('mail.default') === 'log') {
            return redirect()->route('super-admin.password.request')->withErrors([
                'email' => 'Password reset delivery is not configured. Please contact platform support.',
            ]);
        }

        Password::broker('super_admins')->sendResetLink([
            'email' => Str::lower($validator->validated()['email']),
        ]);

        return redirect()->route('super-admin.password.request')->with(
            'status',
            'If an active Super Admin account matches that email, password reset instructions will be sent.'
        );
    }

    public function showResetPassword(string $token): View
    {
        return view('super-admin.auth.reset-password', [
            'token' => $token,
            'email' => request()->query('email', ''),
        ]);
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                'confirmed',
                PasswordRule::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            $email = $request->input('email');
            $token = $request->input('token');

            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && is_string($token) && $token !== '') {
                $redirect = redirect()->route('super-admin.password.reset', [
                    'token' => $token,
                    'email' => $email,
                ]);
            } else {
                $redirect = redirect()->route('super-admin.password.request');
            }

            return $redirect->withErrors($validator)
                ->withInput($request->only('email'));
        }

        $validated = $validator->validated();
        $superAdmin = Password::broker('super_admins')->getUser([
            'email' => Str::lower($validated['email']),
        ]);
        $tokenRecord = DB::table(config('auth.passwords.super_admins.table'))
            ->where('email', Str::lower($validated['email']))
            ->first();

        $tokenExpired = ! $tokenRecord
            || ! isset($tokenRecord->created_at)
            || Carbon::parse($tokenRecord->created_at)
                ->addMinutes((int) config('auth.passwords.super_admins.expire'))
                ->isPast();

        if (! $superAdmin || ! Password::broker('super_admins')->tokenExists($superAdmin, $validated['token']) || $tokenExpired) {
            return redirect()->route('super-admin.password.reset', [
                'token' => $validated['token'],
                'email' => $validated['email'],
            ])->withErrors([
                'email' => 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }

        $status = Password::broker('super_admins')->reset(
            [
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => $validated['password'],
                'token' => $validated['token'],
            ],
            function ($superAdmin, string $password): void {
                $superAdmin->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
                RestaurantAuditLog::create([
                    'actor_id' => $superAdmin->id,
                    'actor_type' => 'super_admin',
                    'action' => 'super_admin_password_reset',
                    'target_type' => 'super_admin',
                    'target_id' => $superAdmin->id,
                    'details' => [],
                ]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return redirect()->route('super-admin.password.reset', [
                'token' => $validated['token'],
                'email' => $validated['email'],
            ])->withErrors([
                'email' => 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }

        return redirect()->route('super-admin.login')
            ->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
