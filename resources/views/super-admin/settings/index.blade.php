@extends('layouts.super-admin')

@section('title', 'Settings')
@section('section', 'Settings')

@section('content')
    @php($general = $settings['general'])
    @php($subscription = $settings['subscription'])
    @php($payment = $settings['payment'])
    @php($notifications = $settings['notifications'])
    <div class="settings-page">
        <div class="page-heading">
            <div>
                <span class="eyebrow">PLATFORM CONFIGURATION</span>
                <h1>Settings</h1>
                <p>Manage platform preferences, operational defaults and your administrator security.</p>
            </div>
        </div>
        <nav class="report-tabs settings-tabs" aria-label="Settings sections">
            @foreach(['general' => 'General', 'subscription' => 'Subscriptions', 'payment' => 'Payments', 'notifications' => 'Notifications', 'branding' => 'Branding', 'security' => 'Security'] as $key => $label)
                <a @class(['is-active' => $tab === $key]) href="{{ route('super-admin.settings.index', ['tab' => $key]) }}">{{ $label }}</a>
            @endforeach
        </nav>

        @if($tab === 'general')
            <section class="form-card settings-panel">
                <div class="section-heading"><div><h2>General preferences</h2><p>Changes to the timezone are applied to the application runtime.</p></div></div>
                <form method="POST" action="{{ route('super-admin.settings.update', 'general') }}" class="settings-form-grid" data-loading-form>
                    @csrf
                    <label class="settings-field">
                        <span class="settings-field-label">Platform name</span>
                        <input class="settings-input" name="platform_name" required maxlength="80" value="{{ old('platform_name', $general['platform_name']) }}">
                        <small class="settings-help">Displayed across the TastyBite platform interface.</small>
                        @error('platform_name')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="settings-field">
                        <span class="settings-field-label">Support email</span>
                        <input class="settings-input" type="email" name="support_email" maxlength="255" value="{{ old('support_email', $general['support_email']) }}">
                        <small class="settings-help">Shown to users who need help with the platform.</small>
                        @error('support_email')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="settings-field">
                        <span class="settings-field-label">Support phone</span>
                        <input class="settings-input" name="support_phone" maxlength="40" value="{{ old('support_phone', $general['support_phone']) }}">
                        <small class="settings-help">Optional contact number for platform support.</small>
                        @error('support_phone')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="settings-field">
                        <span class="settings-field-label">Timezone</span>
                        <input class="settings-input" type="text" name="timezone" list="settings-timezone-options" autocomplete="off" required value="{{ old('timezone', $general['timezone']) }}">
                        <datalist id="settings-timezone-options">
                            @foreach(timezone_identifiers_list() as $timezone)
                                <option value="{{ $timezone }}"></option>
                            @endforeach
                        </datalist>
                        <small class="settings-help">Type to search valid timezone identifiers, for example Asia/Kolkata.</small>
                        @error('timezone')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="settings-field">
                        <span class="settings-field-label">Platform currency</span>
                        <input class="settings-input is-readonly" type="text" value="INR — Indian Rupee" readonly aria-readonly="true">
                        <input type="hidden" name="currency" value="INR">
                        <small class="settings-help">INR is the only currency supported by the current billing schema.</small>
                    </label>
                    <label class="settings-field">
                        <span class="settings-field-label">Date format</span>
                        <select class="settings-select" name="date_format">
                            @foreach(['M j, Y' => 'Oct 13, 2026', 'd/m/Y' => '13/10/2026', 'Y-m-d' => '2026-10-13'] as $format => $sample)
                                <option value="{{ $format }}" @selected(old('date_format', $general['date_format']) === $format)>{{ $sample }}</option>
                            @endforeach
                        </select>
                        <small class="settings-help">Controls supported date displays; stored timestamps are unchanged.</small>
                        @error('date_format')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="settings-form-actions">
                        <button class="button button-primary" type="submit"><span class="button-label">Save general preferences</span></button>
                    </div>
                </form>
            </section>
        @elseif($tab === 'subscription')
            <section class="form-card settings-panel">
                <div class="section-heading"><div><h2>Subscription alerts</h2><p>Configure idempotent reminders for subscriptions nearing expiry.</p></div></div>
                <form method="POST" action="{{ route('super-admin.settings.update', 'subscription') }}" class="settings-form-grid" data-loading-form>
                    @csrf
                    <label class="settings-toggle-row">
                        <input type="checkbox" name="expiry_alerts_enabled" value="1" @checked(old('expiry_alerts_enabled', $subscription['expiry_alerts_enabled']))>
                        <span class="settings-toggle" aria-hidden="true"></span>
                        <span class="settings-toggle-copy"><strong>Enable subscription expiry alerts</strong><small>Expired-subscription alerts are also controlled by notification preferences.</small></span>
                    </label>
                    <fieldset class="settings-choice-group">
                        <legend>Reminder thresholds</legend>
                        <p class="settings-help">Select when in-app reminders should be created before a subscription expires.</p>
                        <div class="settings-choice-list">
                            @foreach([7 => '7 days before', 3 => '3 days before', 1 => '1 day before'] as $days => $label)
                                <label class="settings-choice">
                                    <input type="checkbox" name="expiry_reminder_days[]" value="{{ $days }}" @checked(in_array($days, old('expiry_reminder_days', $subscription['expiry_reminder_days']), false))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    @error('expiry_reminder_days.*')<small class="settings-error settings-full-span">{{ $message }}</small>@enderror
                    <div class="settings-form-actions">
                        <button class="button button-primary" type="submit"><span class="button-label">Save subscription settings</span></button>
                    </div>
                </form>
            </section>
        @elseif($tab === 'payment')
            <section class="form-card settings-panel">
                <div class="section-heading"><div><h2>Manual payment preferences</h2><p>These controls configure manual-entry availability; no payment provider is connected.</p></div></div>
                <form method="POST" action="{{ route('super-admin.settings.update', 'payment') }}" class="settings-form-grid" data-loading-form>
                    @csrf
                    <label class="settings-toggle-row">
                        <input type="checkbox" name="manual_enabled" value="1" @checked(old('manual_enabled', $payment['manual_enabled']))>
                        <span class="settings-toggle" aria-hidden="true"></span>
                        <span class="settings-toggle-copy"><strong>Allow manual payment recording</strong><small>Approvals remain subject to the existing administrator review workflow.</small></span>
                    </label>
                    <label class="settings-toggle-row">
                        <input type="checkbox" name="proof_required" value="1" @checked(old('proof_required', $payment['proof_required']))>
                        <span class="settings-toggle" aria-hidden="true"></span>
                        <span class="settings-toggle-copy"><strong>Require proof attachment</strong><small>Applies only to new manual payment submissions.</small></span>
                    </label>
                    <label class="settings-field">
                        <span class="settings-field-label">Currency</span>
                        <input class="settings-input is-readonly" type="text" value="INR — Indian Rupee" readonly aria-readonly="true">
                        <input type="hidden" name="currency" value="INR">
                        <small class="settings-help">The current manual payment workflow supports INR only.</small>
                    </label>
                    <fieldset class="settings-choice-group">
                        <legend>Available manual methods</legend>
                        <p class="settings-help">Choose which methods administrators can record manually.</p>
                        <div class="settings-choice-list">
                            @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'other' => 'Other'] as $method => $label)
                                <label class="settings-choice">
                                    <input type="checkbox" name="allowed_methods[]" value="{{ $method }}" @checked(in_array($method, old('allowed_methods', $payment['allowed_methods']), true))>
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('allowed_methods')<small class="settings-error">{{ $message }}</small>@enderror
                        @error('allowed_methods.*')<small class="settings-error">{{ $message }}</small>@enderror
                    </fieldset>
                    <label class="settings-field settings-full-span">
                        <span class="settings-field-label">Instructions for administrators</span>
                        <textarea class="settings-textarea" name="instructions" rows="4" maxlength="2000">{{ old('instructions', $payment['instructions']) }}</textarea>
                        <small class="settings-help">Internal guidance shown while recording a manual payment.</small>
                        @error('instructions')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="settings-form-actions">
                        <button class="button button-primary" type="submit"><span class="button-label">Save payment settings</span></button>
                    </div>
                </form>
            </section>
        @elseif($tab === 'notifications')
            <section class="form-card settings-panel">
                <div class="section-heading"><div><h2>Notification preferences</h2><p>These preferences control in-app business-event notification creation.</p></div></div>
                <form method="POST" action="{{ route('super-admin.settings.update', 'notifications') }}" class="settings-form-grid" data-loading-form>
                    @csrf
                    @foreach(['enabled' => ['Enable platform notifications', 'Master control for new in-app notification events.'], 'expiry_alerts' => ['Subscription alerts', 'Expiry reminders and subscription lifecycle notifications.'], 'payment_alerts' => ['Payment alerts', 'Payment submissions, approvals, rejections and recorded refunds.'], 'restaurant_alerts' => ['Restaurant alerts', 'New restaurant and owner creation events.']] as $name => [$label, $help])
                        <label class="settings-toggle-row">
                            <input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $notifications[$name]))>
                            <span class="settings-toggle" aria-hidden="true"></span>
                            <span class="settings-toggle-copy"><strong>{{ $label }}</strong><small>{{ $help }}</small></span>
                        </label>
                    @endforeach
                    <div class="settings-form-actions">
                        <button class="button button-primary" type="submit"><span class="button-label">Save notification settings</span></button>
                    </div>
                </form>
            </section>
        @elseif($tab === 'branding')
            <section class="form-card settings-panel">
                <div class="section-heading"><div><h2>Brand assets</h2><p>Uploads are kept on Laravel's private local disk and served only to authenticated administrators.</p></div></div>
                <form method="POST" action="{{ route('super-admin.settings.branding') }}" enctype="multipart/form-data" class="settings-form-grid" data-loading-form>
                    @csrf
                    <label class="settings-field">
                        <span class="settings-field-label">Platform logo</span>
                        <input class="settings-input settings-file-input" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                        <small class="settings-help">PNG, JPG or WebP; up to 2 MB.</small>
                        @error('logo')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="settings-field">
                        <span class="settings-field-label">Favicon</span>
                        <input class="settings-input settings-file-input" type="file" name="favicon" accept="image/png,image/x-icon">
                        <small class="settings-help">PNG or ICO; up to 512 KB.</small>
                        @error('favicon')<small class="settings-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="settings-form-actions">
                        <button class="button button-primary" type="submit"><span class="button-label">Upload selected brand assets</span></button>
                    </div>
                </form>
                <div class="brand-asset-preview settings-brand-preview">
                    @if($general['logo_path'] ?? null)
                        <div><span>Current logo</span><img src="{{ route('super-admin.settings.branding.show', 'logo') }}" alt="Current platform logo"></div>
                    @else
                        <div class="settings-brand-default"><span>Default brand preview</span><div class="brand-lockup brand-lockup-light"><span class="brand-name">Tasty<span>Bite</span></span></div></div>
                    @endif
                    @if($general['favicon_path'] ?? null)<div><span>Current favicon</span><img class="favicon-preview" src="{{ route('super-admin.settings.branding.show', 'favicon') }}" alt="Current platform favicon"></div>@endif
                </div>
            </section>
        @else
            <div class="settings-security-grid">
                <section class="form-card settings-panel">
                    <div class="section-heading"><div><h2>Administrator profile</h2><p>Update the name and unique login email for your Super Admin account.</p></div></div>
                    <form method="POST" action="{{ route('super-admin.settings.security.profile') }}" class="settings-form-grid" data-loading-form>
                        @csrf
                        @method('PUT')
                        <label class="settings-field">
                            <span class="settings-field-label">Name</span>
                            <input class="settings-input" name="name" required maxlength="255" value="{{ old('name', $superAdmin->name) }}">
                            <small class="settings-help">Name shown in the administrator profile menu.</small>
                            @error('name')<small class="settings-error">{{ $message }}</small>@enderror
                        </label>
                        <label class="settings-field">
                            <span class="settings-field-label">Email</span>
                            <input class="settings-input" type="email" name="email" required maxlength="255" value="{{ old('email', $superAdmin->email) }}">
                            <small class="settings-help">Used as your Super Admin sign-in email.</small>
                            @error('email')<small class="settings-error">{{ $message }}</small>@enderror
                        </label>
                        <div class="settings-form-actions">
                            <button class="button button-primary" type="submit"><span class="button-label">Save profile</span></button>
                        </div>
                    </form>
                </section>
                <section class="form-card settings-panel">
                    <div class="section-heading"><div><h2>Change password</h2><p>Use a unique password with at least 12 characters, mixed case, a number and a symbol.</p></div></div>
                    <form method="POST" action="{{ route('super-admin.settings.security.password') }}" class="settings-form-grid" data-loading-form>
                        @csrf
                        @method('PUT')
                        <label class="settings-field">
                            <span class="settings-field-label">Current password</span>
                            <input class="settings-input" type="password" name="current_password" required autocomplete="current-password">
                            <small class="settings-help">Confirm your identity before changing the account password.</small>
                            @error('current_password')<small class="settings-error">{{ $message }}</small>@enderror
                        </label>
                        <label class="settings-field">
                            <span class="settings-field-label">New password</span>
                            <input class="settings-input" type="password" name="password" required autocomplete="new-password">
                            <small class="settings-help">At least 12 characters with mixed case, a number and a symbol.</small>
                            @error('password')<small class="settings-error">{{ $message }}</small>@enderror
                        </label>
                        <label class="settings-field settings-full-span">
                            <span class="settings-field-label">Confirm new password</span>
                            <input class="settings-input" type="password" name="password_confirmation" required autocomplete="new-password">
                            <small class="settings-help">Enter the new password again to confirm it.</small>
                        </label>
                        <div class="settings-form-actions">
                            <button class="button button-primary" type="submit"><span class="button-label">Change password</span></button>
                        </div>
                    </form>
                </section>
            </div>
        @endif
    </div>
@endsection
