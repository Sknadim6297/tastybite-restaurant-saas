@php($plan = $plan ?? null)
@php($editing = $plan !== null)
<form method="POST" action="{{ $editing ? route('super-admin.subscription-plans.update', $plan) : route('super-admin.subscription-plans.store') }}" class="panel-card form-stack" data-loading-form data-plan-duration-form>
    @csrf
    @if($editing) @method('PUT') @endif
    <div class="form-grid">
        <div class="form-field">
            <label for="name">Plan name</label>
            <input id="name" name="name" value="{{ old('name', $plan?->name ?? '') }}" required maxlength="120" @error('name') aria-invalid="true" @enderror>
            @error('name')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="code">Unique plan code</label>
            <input id="code" name="code" value="{{ old('code', $plan?->code ?? '') }}" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" autocomplete="off" @error('code') aria-invalid="true" @enderror>
            <span class="field-hint">Letters, numbers, underscores, and hyphens. Saved in uppercase.</span>
            @error('code')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="price">Price (INR)</label>
            <input id="price" name="price" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('price', $plan?->price ?? '0.00') }}" required @error('price') aria-invalid="true" @enderror>
            @error('price')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="billing_period">Billing period</label>
            <select id="billing_period" name="billing_period" required>
                @foreach(['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly', 'custom' => 'Custom duration'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('billing_period', $plan?->billing_period ?? 'monthly') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <span class="field-hint" data-standard-duration>Monthly = 1 calendar month, Quarterly = 3 calendar months, Yearly = 12 calendar months.</span>
            @error('billing_period')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field form-field-full" data-custom-duration-fields hidden>
            <label for="custom_duration_unit">Custom duration unit</label>
            <select id="custom_duration_unit" data-custom-duration-unit>
                <option value="months" @selected(old('duration_days', $plan?->billing_period === 'custom' ? $plan?->duration_days ?? '' : '') === '')>Calendar months</option>
                <option value="days" @selected(old('duration_days', $plan?->billing_period === 'custom' ? $plan?->duration_days ?? '' : '') !== '')>Days</option>
            </select>
        </div>
        <div class="form-field" data-duration-input="months" hidden>
            <label for="duration_months">Custom duration in months</label>
            <input id="duration_months" name="duration_months" type="number" min="1" max="120" value="{{ old('duration_months', $plan?->billing_period === 'custom' && $plan?->duration_days === null ? $plan?->duration_months : '') }}">
            @error('duration_months')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field" data-duration-input="days" hidden>
            <label for="duration_days">Custom duration in days</label>
            <input id="duration_days" name="duration_days" type="number" min="1" max="3650" value="{{ old('duration_days', $plan?->billing_period === 'custom' ? $plan?->duration_days : '') }}">
            @error('duration_days')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field form-field-full">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="4" maxlength="2000">{{ old('description', $plan?->description ?? '') }}</textarea>
            @error('description')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="trial_duration_days">Trial duration (days)</label>
            <input id="trial_duration_days" name="trial_duration_days" type="number" min="1" max="365" value="{{ old('trial_duration_days', $plan?->trial_duration_days ?? '') }}" data-trial-duration data-trial-duration-field>
            @error('trial_duration_days')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field">
            <label for="status">Availability</label>
            <select id="status" name="status" required>
                <option value="active" @selected(old('status', $plan?->status ?? 'active') === 'active')>Active</option>
                <option value="inactive" @selected(old('status', $plan?->status ?? 'active') === 'inactive')>Inactive</option>
            </select>
            @error('status')<span class="field-error">{{ $message }}</span>@enderror
        </div>
        <div class="form-field form-field-full checkbox-field">
            <input type="hidden" name="trial_available" value="0">
            <label><input type="checkbox" name="trial_available" value="1" @checked((bool) old('trial_available', $plan?->trial_available ?? false)) data-trial-available> Make this plan available for trial subscriptions</label>
            <span class="field-hint">Trial days are only used when Super Admin explicitly assigns the restaurant as a trial.</span>
            @error('trial_available')<span class="field-error">{{ $message }}</span>@enderror
        </div>
    </div>
    @error('duration')<span class="field-error">{{ $message }}</span>@enderror
    <div class="form-actions">
        <a class="button button-secondary" href="{{ $editing ? route('super-admin.subscription-plans.show', $plan) : route('super-admin.subscription-plans.index') }}">Cancel</a>
        <button class="button button-primary" type="submit"><span class="button-label">{{ $editing ? 'Save changes' : 'Create plan' }}</span><span class="button-spinner" aria-hidden="true"></span></button>
    </div>
</form>
