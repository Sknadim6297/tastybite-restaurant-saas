@extends('layouts.restaurant-admin')

@section('title', 'Inventory Settings')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Restaurant configuration</span><h1>Inventory settings</h1><p>Stock valuation uses weighted average cost; this policy is fixed once stock is posted.</p></div></div>
    <form method="POST" action="{{ route('restaurant.inventory.settings.update') }}" class="form-stack">@csrf @method('PUT')
        <section class="panel-card"><div class="form-grid">
            <div class="form-field"><label for="default_location_id">Default stock location</label><select id="default_location_id" name="default_location_id"><option value="">Select when recording stock</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($settings->default_location_id == $location->id)>{{ $location->name }}</option>@endforeach</select>@error('default_location_id')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="expiry_warning_days">Expiry warning days</label><input id="expiry_warning_days" type="number" name="expiry_warning_days" min="1" max="365" value="{{ old('expiry_warning_days', $settings->expiry_warning_days) }}" required>@error('expiry_warning_days')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="valuation">Valuation method</label><input id="valuation" value="Weighted average cost" disabled><small>Changing costing methods after movements would alter historical values and is not allowed.</small></div>
            <label class="checkbox-field"><input type="checkbox" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $settings->allow_negative_stock))> Allow negative stock</label>
            <label class="checkbox-field"><input type="checkbox" name="consume_recipes" value="1" @checked(old('consume_recipes', $settings->consume_recipes))> Deduct active recipe ingredients when POS orders are completed</label>
            <label class="checkbox-field"><input type="checkbox" name="low_stock_alerts_enabled" value="1" @checked(old('low_stock_alerts_enabled', $settings->low_stock_alerts_enabled))> Show low-stock alerts on the inventory overview</label>
            <label class="checkbox-field"><input type="checkbox" name="expiry_alerts_enabled" value="1" @checked(old('expiry_alerts_enabled', $settings->expiry_alerts_enabled))> Show expiry alerts on the inventory overview</label>
        </div></section>
        <p class="form-help">Inventory alerts are shown in the restaurant dashboard only. No email, SMS, or push notifications are sent.</p>
        <div class="form-actions"><button class="button button-primary" type="submit">Save settings</button></div>
    </form>
@endsection
