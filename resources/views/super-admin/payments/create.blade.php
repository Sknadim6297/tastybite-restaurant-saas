@extends('layouts.super-admin')

@section('title', 'Record Payment')
@section('section', 'Payments')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">SUBSCRIPTION BILLING</span><h1>Record an offline payment</h1><p>Payments remain pending verification until an authorized Super Admin approves them.</p></div></div>
    @if($paymentPreferences['instructions'] !== '')
        <div class="notice-card"><strong>Payment recording guidance</strong><p>{{ $paymentPreferences['instructions'] }}</p></div>
    @endif
    <form
        method="POST"
        action="{{ route('super-admin.payments.store') }}"
        enctype="multipart/form-data"
        class="panel-card form-stack"
        data-loading-form
        data-subscription-payment-form
        data-subscriptions-url="{{ route('super-admin.payments.restaurants.subscriptions', ['restaurant' => '__RESTAURANT__']) }}"
        data-old-subscription="{{ old('restaurant_subscription_id', $selectedSubscriptionId) }}"
        data-old-amount="{{ old('amount', '') }}"
    >
        @csrf
        <div class="form-grid">
            <div class="form-field form-field-full">
                <label for="restaurant_id">Restaurant</label>
                <select id="restaurant_id" name="restaurant_id" required data-payment-restaurant>
                    <option value="">Select a restaurant</option>
                    @foreach($restaurants as $restaurant)
                        <option value="{{ $restaurant->id }}" @selected((string) old('restaurant_id', $selectedRestaurantId) === (string) $restaurant->id)>{{ $restaurant->name }} (ID #{{ $restaurant->id }})</option>
                    @endforeach
                </select>
                @error('restaurant_id')<span class="field-error">{{ $message }}</span>@enderror
            </div>

            <div class="form-field form-field-full">
                <span class="section-heading"><strong>Restaurant details</strong></span>
                <div class="detail-grid">
                    <div class="detail-item"><span>Restaurant name / ID</span><strong data-payment-restaurant-name>—</strong></div>
                    <div class="detail-item"><span>Restaurant email</span><strong data-payment-restaurant-email>—</strong></div>
                    <div class="detail-item"><span>Restaurant phone</span><strong data-payment-restaurant-phone>—</strong></div>
                    <div class="detail-item"><span>Owner name</span><strong data-payment-owner-name>—</strong></div>
                    <div class="detail-item"><span>Owner email</span><strong data-payment-owner-email>—</strong></div>
                    <div class="detail-item"><span>Owner mobile</span><strong data-payment-owner-mobile>Not recorded</strong></div>
                </div>
            </div>

            <div class="form-field form-field-full">
                <label for="restaurant_subscription_id">Existing subscription</label>
                <select id="restaurant_subscription_id" name="restaurant_subscription_id" required disabled data-payment-subscription>
                    <option value="">Select a restaurant first</option>
                </select>
                @error('restaurant_subscription_id')<span class="field-error">{{ $message }}</span>@enderror
                <span class="field-hint" data-payment-loader role="status" aria-live="polite" hidden>Loading subscription details...</span>
                <span class="field-error" data-payment-load-error role="alert" hidden></span>
                <span class="field-hint" data-payment-empty hidden>No subscription found for this restaurant.</span>
            </div>

            <div class="form-field form-field-full">
                <strong>Subscription details</strong>
                <div class="detail-grid">
                    <div class="detail-item"><span>Subscription ID</span><strong data-payment-subscription-id>—</strong></div>
                    <div class="detail-item"><span>Plan name</span><strong data-payment-plan-name>—</strong></div>
                    <div class="detail-item"><span>Plan code</span><strong data-payment-plan-code>—</strong></div>
                    <div class="detail-item"><span>Plan price</span><strong data-payment-plan-price>—</strong></div>
                    <div class="detail-item"><span>Billing duration</span><strong data-payment-duration>—</strong></div>
                    <div class="detail-item"><span>Start date</span><strong data-payment-start>—</strong></div>
                    <div class="detail-item"><span>Expiry date</span><strong data-payment-expiry>—</strong></div>
                    <div class="detail-item"><span>Subscription status</span><strong data-payment-status>—</strong></div>
                </div>
            </div>

            <div class="form-field form-field-full">
                <strong>Payment summary</strong>
                <div class="detail-grid">
                    <div class="detail-item"><span>Total payable</span><strong data-payment-total>—</strong></div>
                    <div class="detail-item"><span>Already paid</span><strong data-payment-paid>—</strong></div>
                    <div class="detail-item"><span>Outstanding amount</span><strong data-payment-outstanding>—</strong></div>
                    <div class="detail-item"><span>Pending payments</span><strong data-payment-pending>—</strong></div>
                    <div class="detail-item"><span>Payment status</span><strong data-payment-balance-status>—</strong></div>
                </div>
                <span class="field-hint" data-payment-fully-paid hidden>Fully Paid</span>
                <span class="field-hint" data-payment-no-available hidden>The outstanding balance is reserved by pending payments.</span>
            </div>

            <div class="form-field">
                <label for="amount">Payment amount (INR)</label>
                <input id="amount" type="number" name="amount" min="0.01" max="99999999.99" step="0.01" value="{{ old('amount') }}" required data-payment-amount>
                @error('amount')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            <div class="form-field"><label for="payment_method">Payment method</label><select id="payment_method" name="payment_method" required>@foreach(['cash'=>'Cash','bank_transfer'=>'Bank Transfer','upi'=>'UPI','other'=>'Other'] as $value=>$label)@if(in_array($value, $paymentPreferences['allowed_methods'], true))<option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>@endif @endforeach</select>@error('payment_method')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="transaction_reference">Transaction reference <span class="field-hint-inline">(optional)</span></label><input id="transaction_reference" name="transaction_reference" value="{{ old('transaction_reference') }}" maxlength="255">@error('transaction_reference')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="paid_at">Payment date</label><input id="paid_at" type="datetime-local" name="paid_at" value="{{ old('paid_at', $paidAt) }}" required>@error('paid_at')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="proof">Payment proof <span class="field-hint-inline">({{ $paymentPreferences['proof_required'] ? 'required' : 'optional' }}; PDF, JPG or PNG, up to 5 MB)</span></label><input id="proof" type="file" name="proof" accept=".pdf,.jpg,.jpeg,.png" @required($paymentPreferences['proof_required'])>@error('proof')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field form-field-full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="4" maxlength="5000">{{ old('notes') }}</textarea>@error('notes')<span class="field-error">{{ $message }}</span>@enderror</div>
        </div>
        <div class="form-actions"><a class="button button-secondary" href="{{ route('super-admin.payments.index') }}">Cancel</a><button class="button button-primary" type="submit" disabled data-payment-submit><span class="button-label">Record pending payment</span><span class="button-spinner" aria-hidden="true"></span></button></div>
    </form>
@endsection
