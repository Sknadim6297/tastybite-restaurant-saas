@extends('layouts.super-admin')

@section('title', 'Record Payment')
@section('section', 'Payments')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">MANUAL PAYMENT ENTRY</span><h1>Record a payment</h1><p>New entries remain pending and do not change subscription access until approved.</p></div></div>
    @if($plans->isEmpty())
        <div class="notice-card"><strong>No active subscription plans are available.</strong><p>Activate a subscription plan before recording a payment that can be approved.</p><a class="button button-secondary" href="{{ route('super-admin.subscription-plans.index') }}">Manage subscription plans</a></div>
    @endif
    @if($paymentPreferences['instructions'] !== '')
        <div class="notice-card"><strong>Payment recording guidance</strong><p>{{ $paymentPreferences['instructions'] }}</p></div>
    @endif
    <form method="POST" action="{{ route('super-admin.payments.store') }}" enctype="multipart/form-data" class="panel-card form-stack" data-loading-form>
        @csrf
        <div class="form-grid">
            <div class="form-field"><label for="restaurant_id">Restaurant</label><select id="restaurant_id" name="restaurant_id" required><option value="">Select a restaurant</option>@foreach($restaurants as $restaurant)<option value="{{ $restaurant->id }}" @selected((string) old('restaurant_id') === (string) $restaurant->id)>{{ $restaurant->name }}</option>@endforeach</select>@error('restaurant_id')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="subscription_plan_id">Subscription plan</label><select id="subscription_plan_id" name="subscription_plan_id" required><option value="">Select an active plan</option>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected((string) old('subscription_plan_id') === (string) $plan->id)>{{ $plan->name }} — {{ $plan->currency }} {{ $plan->price }}</option>@endforeach</select>@error('subscription_plan_id')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="amount">Amount</label><input id="amount" type="number" name="amount" min="0.01" max="99999999.99" step="0.01" value="{{ old('amount') }}" required>@error('amount')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="currency">Currency</label><select id="currency" name="currency" required><option value="INR">INR — Indian Rupee</option></select></div>
            <div class="form-field"><label for="payment_method">Payment method</label><select id="payment_method" name="payment_method" required>@foreach(['cash'=>'Cash','bank_transfer'=>'Bank Transfer','upi'=>'UPI','other'=>'Other'] as $value=>$label)@if(in_array($value, $paymentPreferences['allowed_methods'], true))<option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>@endif @endforeach</select>@error('payment_method')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="transaction_reference">Transaction reference <span class="field-hint-inline">(optional)</span></label><input id="transaction_reference" name="transaction_reference" value="{{ old('transaction_reference') }}" maxlength="255">@error('transaction_reference')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="paid_at">Payment date</label><input id="paid_at" type="datetime-local" name="paid_at" value="{{ old('paid_at', $paidAt) }}" required>@error('paid_at')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="proof">Payment proof <span class="field-hint-inline">({{ $paymentPreferences['proof_required'] ? 'required' : 'optional' }}; PDF, JPG or PNG, up to 5 MB)</span></label><input id="proof" type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png" @required($paymentPreferences['proof_required'])>@error('proof')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field form-field-full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="4" maxlength="5000">{{ old('notes') }}</textarea>@error('notes')<span class="field-error">{{ $message }}</span>@enderror</div>
        </div>
        <div class="form-actions"><a class="button button-secondary" href="{{ route('super-admin.payments.index') }}">Cancel</a><button class="button button-primary" type="submit" @disabled($plans->isEmpty())><span class="button-label">Record pending payment</span><span class="button-spinner" aria-hidden="true"></span></button></div>
    </form>
@endsection
