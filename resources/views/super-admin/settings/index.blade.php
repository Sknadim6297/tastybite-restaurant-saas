@extends('layouts.super-admin')

@section('title', 'Settings')
@section('section', 'Settings')

@section('content')
    @php($general = $settings['general'])
    @php($subscription = $settings['subscription'])
    @php($payment = $settings['payment'])
    @php($notifications = $settings['notifications'])
    <div class="page-heading"><div><span class="eyebrow">PLATFORM CONFIGURATION</span><h1>Settings</h1><p>Manage platform preferences, operational defaults and your administrator security.</p></div></div>
    <nav class="report-tabs settings-tabs" aria-label="Settings sections">
        @foreach(['general' => 'General', 'subscription' => 'Subscriptions', 'payment' => 'Payments', 'notifications' => 'Notifications', 'branding' => 'Branding', 'security' => 'Security'] as $key => $label)
            <a @class(['is-active' => $tab === $key]) href="{{ route('super-admin.settings.index', ['tab' => $key]) }}">{{ $label }}</a>
        @endforeach
    </nav>

    @if($tab === 'general')
        <section class="form-card settings-panel">
            <div class="section-heading"><div><h2>General preferences</h2><p>Changes to the timezone are applied to the application runtime.</p></div></div>
            <form method="POST" action="{{ route('super-admin.settings.update', 'general') }}" class="form-grid">@csrf
                <label>Platform name<input name="platform_name" required maxlength="80" value="{{ old('platform_name', $general['platform_name']) }}">@error('platform_name')<small class="field-error">{{ $message }}</small>@enderror</label>
                <label>Support email<input type="email" name="support_email" maxlength="255" value="{{ old('support_email', $general['support_email']) }}">@error('support_email')<small class="field-error">{{ $message }}</small>@enderror</label>
                <label>Support phone<input name="support_phone" maxlength="40" value="{{ old('support_phone', $general['support_phone']) }}">@error('support_phone')<small class="field-error">{{ $message }}</small>@enderror</label>
                <label>Timezone<select name="timezone">@foreach(timezone_identifiers_list() as $timezone)<option value="{{ $timezone }}" @selected(old('timezone', $general['timezone']) === $timezone)>{{ $timezone }}</option>@endforeach</select>@error('timezone')<small class="field-error">{{ $message }}</small>@enderror</label>
                <label>Platform currency<select name="currency"><option value="INR" @selected($general['currency'] === 'INR')>INR — Indian Rupee</option></select><small>Additional currencies are not supported by the current payment schema.</small></label>
                <label>Date format<select name="date_format">@foreach(['M j, Y' => 'Oct 13, 2026', 'd/m/Y' => '13/10/2026', 'Y-m-d' => '2026-10-13'] as $format => $sample)<option value="{{ $format }}" @selected($general['date_format'] === $format)>{{ $sample }}</option>@endforeach</select></label>
                <div class="full-span"><button class="button button-primary" type="submit">Save general preferences</button></div>
            </form>
        </section>
    @elseif($tab === 'subscription')
        <section class="form-card settings-panel">
            <div class="section-heading"><div><h2>Subscription alerts</h2><p>Configure idempotent reminders for subscriptions nearing expiry.</p></div></div>
            <form method="POST" action="{{ route('super-admin.settings.update', 'subscription') }}" class="settings-form">@csrf
                <label class="check-row"><input type="checkbox" name="expiry_alerts_enabled" value="1" @checked(old('expiry_alerts_enabled', $subscription['expiry_alerts_enabled']))><span><strong>Enable subscription expiry alerts</strong><small>Expired-subscription alerts are also controlled by notification preferences.</small></span></label>
                <fieldset class="settings-fieldset"><legend>Reminder thresholds</legend><div class="check-list">
                    @foreach([7 => '7 days before', 3 => '3 days before', 1 => '1 day before'] as $days => $label)
                        <label class="check-row"><input type="checkbox" name="expiry_reminder_days[]" value="{{ $days }}" @checked(in_array($days, old('expiry_reminder_days', $subscription['expiry_reminder_days']), false))><span>{{ $label }}</span></label>
                    @endforeach
                </div></fieldset>
                @error('expiry_reminder_days.*')<small class="field-error">{{ $message }}</small>@enderror
                <button class="button button-primary" type="submit">Save subscription settings</button>
            </form>
        </section>
    @elseif($tab === 'payment')
        <section class="form-card settings-panel">
            <div class="section-heading"><div><h2>Manual payment preferences</h2><p>These controls configure manual-entry availability; no payment provider is connected.</p></div></div>
            <form method="POST" action="{{ route('super-admin.settings.update', 'payment') }}" class="settings-form">@csrf
                <label class="check-row"><input type="checkbox" name="manual_enabled" value="1" @checked(old('manual_enabled', $payment['manual_enabled']))><span><strong>Allow manual payment recording</strong><small>Approvals remain subject to the existing administrator review workflow.</small></span></label>
                <label class="check-row"><input type="checkbox" name="proof_required" value="1" @checked(old('proof_required', $payment['proof_required']))><span><strong>Require proof attachment for new manual payments</strong></span></label>
                <label class="settings-inline-label">Currency<select name="currency"><option value="INR" @selected($payment['currency'] === 'INR')>INR — Indian Rupee</option></select></label>
                <fieldset class="settings-fieldset"><legend>Available manual methods</legend><div class="check-list">
                    @foreach(['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'other' => 'Other'] as $method => $label)
                        <label class="check-row"><input type="checkbox" name="allowed_methods[]" value="{{ $method }}" @checked(in_array($method, old('allowed_methods', $payment['allowed_methods']), true))><span>{{ $label }}</span></label>
                    @endforeach
                </div></fieldset>
                @error('allowed_methods')<small class="field-error">{{ $message }}</small>@enderror
                @error('allowed_methods.*')<small class="field-error">{{ $message }}</small>@enderror
                <label class="settings-inline-label">Instructions for administrators<textarea name="instructions" rows="4" maxlength="2000">{{ old('instructions', $payment['instructions']) }}</textarea>@error('instructions')<small class="field-error">{{ $message }}</small>@enderror</label>
                <button class="button button-primary" type="submit">Save payment settings</button>
            </form>
        </section>
    @elseif($tab === 'notifications')
        <section class="form-card settings-panel">
            <div class="section-heading"><div><h2>Notification preferences</h2><p>These preferences control in-app business-event notification creation.</p></div></div>
            <form method="POST" action="{{ route('super-admin.settings.update', 'notifications') }}" class="settings-form">@csrf
                @foreach(['enabled' => ['Enable platform notifications', 'Master control for new in-app notification events.'], 'expiry_alerts' => ['Subscription alerts', 'Expiry reminders and subscription lifecycle notifications.'], 'payment_alerts' => ['Payment alerts', 'Payment submissions, approvals, rejections and recorded refunds.'], 'restaurant_alerts' => ['Restaurant alerts', 'New restaurant and owner creation events.']] as $name => [$label, $help])
                    <label class="check-row"><input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $notifications[$name]))><span><strong>{{ $label }}</strong><small>{{ $help }}</small></span></label>
                @endforeach
                <label class="settings-inline-label">Visible-tab polling interval (seconds)<input type="number" name="polling_interval" min="30" max="300" value="{{ old('polling_interval', $notifications['polling_interval']) }}">@error('polling_interval')<small class="field-error">{{ $message }}</small>@enderror</label>
                <button class="button button-primary" type="submit">Save notification settings</button>
            </form>
        </section>
    @elseif($tab === 'branding')
        <section class="form-card settings-panel">
            <div class="section-heading"><div><h2>Brand assets</h2><p>Uploads are kept on Laravel's private local disk and served only to authenticated administrators.</p></div></div>
            <form method="POST" action="{{ route('super-admin.settings.branding') }}" enctype="multipart/form-data" class="form-grid">@csrf
                <label>Platform logo (PNG, JPG or WebP; up to 2 MB)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp">@error('logo')<small class="field-error">{{ $message }}</small>@enderror</label>
                <label>Favicon (PNG or ICO; up to 512 KB)<input type="file" name="favicon" accept="image/png,image/x-icon">@error('favicon')<small class="field-error">{{ $message }}</small>@enderror</label>
                <div class="full-span"><button class="button button-primary" type="submit">Upload selected brand assets</button></div>
            </form>
            <div class="brand-asset-preview">
                @if($general['logo_path'] ?? null)<div><span>Current logo</span><img src="{{ route('super-admin.settings.branding.show', 'logo') }}" alt="Current platform logo"></div>@else<p>No custom logo uploaded.</p>@endif
                @if($general['favicon_path'] ?? null)<div><span>Current favicon</span><img class="favicon-preview" src="{{ route('super-admin.settings.branding.show', 'favicon') }}" alt="Current platform favicon"></div>@endif
            </div>
        </section>
    @else
        <div class="settings-security-grid">
            <section class="form-card settings-panel">
                <div class="section-heading"><div><h2>Administrator profile</h2><p>Update the name and unique login email for your Super Admin account.</p></div></div>
                <form method="POST" action="{{ route('super-admin.settings.security.profile') }}" class="settings-form">@csrf @method('PUT')
                    <label>Name<input name="name" required maxlength="255" value="{{ old('name', $superAdmin->name) }}">@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label>Email<input type="email" name="email" required maxlength="255" value="{{ old('email', $superAdmin->email) }}">@error('email')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <button class="button button-primary" type="submit">Save profile</button>
                </form>
            </section>
            <section class="form-card settings-panel">
                <div class="section-heading"><div><h2>Change password</h2><p>Use a unique password with at least 12 characters, mixed case, a number and a symbol.</p></div></div>
                <form method="POST" action="{{ route('super-admin.settings.security.password') }}" class="settings-form">@csrf @method('PUT')
                    <label>Current password<input type="password" name="current_password" required autocomplete="current-password">@error('current_password')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label>New password<input type="password" name="password" required autocomplete="new-password">@error('password')<small class="field-error">{{ $message }}</small>@enderror</label>
                    <label>Confirm new password<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
                    <button class="button button-primary" type="submit">Change password</button>
                </form>
            </section>
        </div>
    @endif
@endsection
