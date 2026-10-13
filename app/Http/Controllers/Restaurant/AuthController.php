<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAuditLog;
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
    public function showLogin(): View
    {
        return view('restaurant.auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('restaurant.login')
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }

        $credentials = $validator->validated();
        $credentials['email'] = Str::lower($credentials['email']);

        if (! Auth::guard('web')->attempt($credentials)) {
            return redirect()->route('restaurant.login')
                ->withErrors(['email' => 'These credentials do not match our records or your account/restaurant is inactive.'])
                ->withInput($request->only('email'));
        }

        $user = Auth::guard('web')->user();

        // Extra check for inactive restaurant or inactive account
        if (! $user->isActive() || ! $user->restaurant || ! $user->restaurant->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('restaurant.login')
                ->withErrors(['email' => 'Your account or restaurant is currently inactive or suspended. Please contact platform administration.'])
                ->withInput($request->only('email'));
        }

        $request->session()->regenerate();
        $request->session()->put(
            'restaurant_user_password_version',
            hash('sha256', $user->getAuthPassword())
        );

        if ($user->isOwner()) {
            DB::transaction(function () use ($user): void {
                $user->forceFill(['last_login_at' => now()])->save();

                RestaurantAuditLog::create([
                    'actor_type' => 'restaurant_owner',
                    'action' => 'owner_login_success',
                    'target_type' => 'restaurant_user',
                    'target_id' => $user->id,
                    'details' => [],
                ]);
            });
        }

        return redirect()->route('restaurant.dashboard')
            ->with('status', 'Welcome back, '.$user->name.'.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('restaurant.login')
            ->with('status', 'You have been signed out.');
    }

    public function showForgotPassword(): View
    {
        return view('restaurant.auth.forgot-password');
    }

    public function sendResetLink(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return redirect()->route('restaurant.password.request')
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }

        if (config('mail.default') === 'log') {
            return redirect()->route('restaurant.password.request')->withErrors([
                'email' => 'Password reset delivery is not configured. Please contact platform support.',
            ]);
        }

        Password::broker('users')->sendResetLink([
            'email' => Str::lower($validator->validated()['email']),
        ]);

        return redirect()->route('restaurant.password.request')->with(
            'status',
            'If an active restaurant account matches that email, password reset instructions will be sent.'
        );
    }

    public function showResetPassword(string $token): View
    {
        return view('restaurant.auth.reset-password', [
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
                PasswordRule::min(8),
            ],
        ]);

        if ($validator->fails()) {
            $email = $request->input('email');
            $token = $request->input('token');

            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) && is_string($token) && $token !== '') {
                $redirect = redirect()->route('restaurant.password.reset', [
                    'token' => $token,
                    'email' => $email,
                ]);
            } else {
                $redirect = redirect()->route('restaurant.password.request');
            }

            return $redirect->withErrors($validator)
                ->withInput($request->only('email'));
        }

        $validated = $validator->validated();
        $user = Password::broker('users')->getUser([
            'email' => Str::lower($validated['email']),
        ]);
        $tokenRecord = DB::table(config('auth.passwords.users.table'))
            ->where('email', Str::lower($validated['email']))
            ->first();

        $tokenExpired = ! $tokenRecord
            || ! isset($tokenRecord->created_at)
            || Carbon::parse($tokenRecord->created_at)
                ->addMinutes((int) config('auth.passwords.users.expire'))
                ->isPast();

        if (! $user || ! Password::broker('users')->tokenExists($user, $validated['token']) || $tokenExpired) {
            return redirect()->route('restaurant.password.reset', [
                'token' => $validated['token'],
                'email' => $validated['email'],
            ])->withErrors([
                'email' => 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }

        $status = Password::broker('users')->reset(
            [
                'email' => Str::lower($validated['email']),
                'password' => $validated['password'],
                'password_confirmation' => $validated['password'],
                'token' => $validated['token'],
            ],
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return redirect()->route('restaurant.password.reset', [
                'token' => $validated['token'],
                'email' => $validated['email'],
            ])->withErrors([
                'email' => 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }

        return redirect()->route('restaurant.login')
            ->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
