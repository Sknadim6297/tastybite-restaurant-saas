<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\RestaurantAuditLog;
use App\Models\SuperAdmin;
use App\Services\PlatformSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PlatformSettingsController extends Controller
{
    public function __construct(private readonly PlatformSettingsService $settings) {}

    public function index(Request $request): View
    {
        $tab = $request->query('tab', 'general');
        abort_unless(in_array($tab, ['general', 'subscription', 'payment', 'notifications', 'security', 'branding'], true), 404);

        return view('super-admin.settings.index', [
            'settings' => $this->settings->all(),
            'tab' => $tab,
            'superAdmin' => Auth::guard('super_admin')->user(),
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        $actor = $this->actor();

        switch ($group) {
            case 'general':
                $validated = $request->validate([
                    'platform_name' => ['required', 'string', 'min:2', 'max:80'],
                    'support_email' => ['nullable', 'email', 'max:255'],
                    'support_phone' => ['nullable', 'string', 'max:40'],
                    'timezone' => ['required', 'timezone'],
                    'currency' => ['required', Rule::in(['INR'])],
                    'date_format' => ['required', Rule::in(['M j, Y', 'd/m/Y', 'Y-m-d'])],
                ]);
                break;
            case 'subscription':
                $validated = $request->validate([
                    'expiry_reminder_days' => ['nullable', 'array', 'max:3'],
                    'expiry_reminder_days.*' => ['integer', Rule::in([1, 3, 7])],
                ]);
                $validated['expiry_reminder_days'] = array_values(array_unique(array_map('intval', $validated['expiry_reminder_days'] ?? [])));
                $validated['expiry_alerts_enabled'] = $request->boolean('expiry_alerts_enabled');
                break;
            case 'payment':
                $validated = $request->validate([
                    'currency' => ['required', Rule::in(['INR'])],
                    'allowed_methods' => ['nullable', 'array', 'max:4'],
                    'allowed_methods.*' => [Rule::in(['cash', 'bank_transfer', 'upi', 'other'])],
                    'instructions' => ['nullable', 'string', 'max:2000'],
                ]);
                $validated['manual_enabled'] = $request->boolean('manual_enabled');
                $validated['proof_required'] = $request->boolean('proof_required');
                $validated['allowed_methods'] = array_values(array_unique($validated['allowed_methods'] ?? []));
                if ($validated['manual_enabled'] && $validated['allowed_methods'] === []) {
                    return back()->withErrors(['allowed_methods' => 'At least one manual payment method is required while manual payments are enabled.'])->withInput();
                }
                break;
            case 'notifications':
                $validated = [];
                foreach (['enabled', 'expiry_alerts', 'payment_alerts', 'restaurant_alerts'] as $toggle) {
                    $validated[$toggle] = $request->boolean($toggle);
                }
                break;
            default:
                abort(404);
        }

        $this->settings->saveGroup($group, $validated, $actor);

        return redirect()->route('super-admin.settings.index', ['tab' => $group])
            ->with('status', 'Platform settings saved.');
    }

    public function updateBranding(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:max_width=2000,max_height=2000'],
            'favicon' => ['nullable', 'image', 'mimes:png,ico', 'max:512', 'dimensions:max_width=512,max_height=512'],
        ]);
        $values = [];
        foreach (['logo', 'favicon'] as $kind) {
            if (! isset($validated[$kind])) {
                continue;
            }
            $key = $kind.'_path';
            $path = $validated[$kind]->store('platform-branding', 'local');
            if (! is_string($path)) {
                throw new \RuntimeException('The platform branding file could not be stored securely.');
            }
            $values[$key] = $path;
        }

        if ($values !== []) {
            $actor = $this->actor();
            $current = $this->settings->group('general');
            $this->settings->saveGroup('general', array_replace($current, $values), $actor);
        }

        return redirect()->route('super-admin.settings.index', ['tab' => 'branding'])
            ->with('status', $values === [] ? 'No branding files were changed.' : 'Branding files saved in private storage.');
    }

    public function branding(string $kind): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['logo', 'favicon'], true), 404);
        $path = $this->settings->group('general')[$kind.'_path'] ?? null;
        abort_unless(
            is_string($path)
            && str_starts_with($path, 'platform-branding/')
            && basename($path) === substr($path, strlen('platform-branding/'))
            && Storage::disk('local')->exists($path),
            404,
        );

        return response()->file(Storage::disk('local')->path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $admin = $this->actor();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('super_admins', 'email')->ignore($admin->id)],
        ]);
        $before = $admin->only(['name', 'email']);
        $admin->update($validated);
        $this->audit($admin, 'super_admin_profile_updated', ['before' => $before, 'after' => $admin->only(['name', 'email'])]);

        return redirect()->route('super-admin.settings.index', ['tab' => 'security'])->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $admin = $this->actor();
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        if (! Hash::check($validated['current_password'], $admin->getAuthPassword())) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.'])->withInput();
        }

        $admin->forceFill(['password' => $validated['password']])->save();
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        request()->session()->put('super_admin_password_version', hash('sha256', $admin->getAuthPassword()));
        $this->audit($admin, 'super_admin_password_changed', ['password' => '[redacted]']);

        return redirect()->route('super-admin.settings.index', ['tab' => 'security'])->with('status', 'Password updated. Sign in again on other devices.');
    }

    private function actor(): SuperAdmin
    {
        return Auth::guard('super_admin')->user();
    }

    private function audit(SuperAdmin $actor, string $action, array $details): void
    {
        RestaurantAuditLog::create([
            'actor_id' => $actor->id,
            'actor_type' => 'super_admin',
            'action' => $action,
            'target_type' => 'super_admin',
            'target_id' => $actor->id,
            'details' => $details,
        ]);
    }
}
