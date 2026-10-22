@extends('layouts.super-admin')

@section('title', 'New Support Access Request')
@section('section', 'Support Access')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">REQUEST REVIEW</span><h1>New support-access request</h1><p>Requests require approval by a different Super Admin. Dashboard/menu access starts only after approval and is time-limited.</p></div>
        <a class="button button-secondary" href="{{ route('super-admin.support-access.index') }}">Back to requests</a>
    </div>

    <div class="notice-card" role="note"><strong>Limited, auditable access</strong><p>The dashboard/menu scope reuses the restaurant panel without logging in as its owner. It permits dashboard viewing and category/product creation or editing only; POS, billing, deletion, account and security actions remain blocked.</p></div>
    <div class="form-card">
        <form method="POST" action="{{ route('super-admin.support-access.store') }}" class="form-stack" data-loading-form>
            @csrf
            <div class="form-grid">
                <div class="form-field"><label for="restaurant_id">Restaurant</label><select id="restaurant_id" name="restaurant_id" required><option value="">Select restaurant</option>@foreach ($restaurants as $restaurant)<option value="{{ $restaurant->id }}" @selected((string) old('restaurant_id', $restaurantId) === (string) $restaurant->id)>{{ $restaurant->name }} — {{ ucfirst($restaurant->status) }}</option>@endforeach</select>@error('restaurant_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="form-field"><label for="requested_scope">Support scope</label><select id="requested_scope" name="requested_scope" required><option value="">Select scope</option>@foreach (['billing' => 'Billing', 'technical' => 'Technical', 'account' => 'Account', 'other' => 'Other', \App\Models\SupportAccessRequest::RESTAURANT_DASHBOARD_MENU_MANAGE => 'Restaurant dashboard and menu management'] as $scope => $label)<option value="{{ $scope }}" @selected(old('requested_scope', $requestedScope) === $scope)>{{ $label }}</option>@endforeach</select>@error('requested_scope')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="form-field"><label for="requested_duration_minutes">Requested support window</label><select id="requested_duration_minutes" name="requested_duration_minutes" required>@foreach ([30, 60, 120, 240, 480] as $minutes)<option value="{{ $minutes }}" @selected((int) old('requested_duration_minutes', 60) === $minutes)>{{ $minutes < 60 ? $minutes.' minutes' : ($minutes / 60).' hours' }}</option>@endforeach</select>@error('requested_duration_minutes')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="form-field full-span"><label for="reason">Business justification</label><textarea id="reason" name="reason" rows="5" required minlength="20" maxlength="2000">{{ old('reason') }}</textarea>@error('reason')<span class="field-error">{{ $message }}</span>@enderror</div>
            </div>
            <div class="page-tools" style="margin:0; justify-content:flex-end;"><a class="button button-secondary" href="{{ route('super-admin.support-access.index') }}">Cancel</a><button class="button button-primary" type="submit">Submit for independent review</button></div>
        </form>
    </div>
@endsection
