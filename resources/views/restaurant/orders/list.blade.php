@extends('layouts.restaurant-admin')

@section('title', $title)
@section('section', 'Orders Management')

@section('content')
    <div class="page-heading orders-page-heading">
        <div>
            <span class="eyebrow">ORDERS MANAGEMENT</span>
            <h1>{{ $title }}</h1>
            <p>Review restaurant orders, fulfillment and payment records.</p>
        </div>
        <a class="button button-primary" href="{{ route('pos.index') }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> Create order in POS</a>
    </div>

    @if(session('status'))
        <div class="notice-card" role="status">{{ session('status') }}</div>
    @endif

    @if(in_array($kind, ['takeaway', 'delivery'], true))
        <nav class="order-tabs" aria-label="Order type">
            <a href="{{ route('restaurant.orders.fulfillment', ['type' => 'takeaway']) }}" @class(['is-active' => $kind === 'takeaway'])>Takeaway</a>
            <a href="{{ route('restaurant.orders.fulfillment', ['type' => 'delivery']) }}" @class(['is-active' => $kind === 'delivery'])>Delivery</a>
        </nav>
    @endif

    <form method="GET" action="{{ url()->current() }}" class="panel-card order-filter-toolbar" id="order-filters">
        @if($kind === 'fulfillment')
            <input type="hidden" name="type" value="{{ request('type', 'takeaway') }}">
        @endif
        <div class="form-field order-search-field">
            <label for="order-search">Search order number</label>
            <input id="order-search" type="search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="120" placeholder="Search order number">
        </div>
        <div class="form-field">
            <label for="order-status">Order status</label>
            <select id="order-status" name="status">
                <option value="">All statuses</option>
                @foreach(['pending', 'confirmed', 'preparing', 'ready', 'served', 'dispatched', 'completed', 'cancelled'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label for="order-payment-status">Payment status</label>
            <select id="order-payment-status" name="payment_status">
                <option value="">All payments</option>
                @foreach(['unpaid', 'paid', 'partially_refunded', 'refunded'] as $status)
                    <option value="{{ $status }}" @selected(($filters['payment_status'] ?? '') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                @endforeach
            </select>
        </div>
        @if($kind === 'all' || $kind === 'billing')
            <div class="form-field">
                <label for="order-type">Order type</label>
                <select id="order-type" name="order_type">
                    <option value="">All types</option>
                    @foreach(['dine_in' => 'Dine-In', 'takeaway' => 'Takeaway', 'delivery' => 'Delivery'] as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['order_type'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if($kind === 'dine_in')
            <div class="form-field">
                <label for="order-table">Table</label>
                <input id="order-table" name="table" value="{{ $filters['table'] ?? '' }}" maxlength="32" placeholder="Table number">
            </div>
        @endif
        <div class="form-field">
            <label for="order-payment-method">Payment method</label>
            <select id="order-payment-method" name="payment_method">
                <option value="">All methods</option>
                @foreach(['cash' => 'Cash', 'card' => 'Card', 'upi' => 'UPI'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['payment_method'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-field">
            <label for="order-from">From</label>
            <input id="order-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}">
        </div>
        <div class="form-field">
            <label for="order-to">To</label>
            <input id="order-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}">
        </div>
        <button class="button button-secondary order-filter-clear" type="button" id="clear-order-filters">Clear filters</button>
        <span class="order-filter-status" id="order-filter-status" role="status" aria-live="polite" hidden>Loading orders...</span>
    </form>

    <div id="order-results" aria-live="polite">
        @include('restaurant.orders.partials.order-list')
    </div>
    <script src="{{ asset('assets/order-filters.js') }}?v={{ filemtime(public_path('assets/order-filters.js')) }}" defer></script>
@endsection
