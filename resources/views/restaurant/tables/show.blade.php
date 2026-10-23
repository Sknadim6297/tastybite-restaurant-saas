@extends('layouts.restaurant-admin')

@section('title', $table->table_number.' Details')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => $table->table_number.' Details', 'description' => 'Table setup, active dining session, reservation and service history.'])
    <section class="table-kpi-grid">
        <article class="table-kpi-card"><small>Current status</small><strong>{{ str($table->status)->replace('_', ' ')->title() }}</strong></article>
        <article class="table-kpi-card"><small>Floor / section</small><strong>{{ $table->floor?->name }} · {{ $table->section?->name ?? '—' }}</strong></article>
        <article class="table-kpi-card"><small>Seating capacity</small><strong>{{ $table->capacity }}</strong></article>
        <article class="table-kpi-card"><small>Active guests</small><strong>{{ $table->activeOccupancy?->guest_count ?? 0 }}</strong></article>
        <article class="table-kpi-card"><small>Occupied since</small><strong>{{ $table->activeOccupancy?->started_at?->timezone(config('app.timezone'))->format('M j · H:i') ?? '—' }}</strong></article>
        <article class="table-kpi-card"><small>Active order</small><strong>@if($table->activeOccupancy?->order)<a href="{{ route('restaurant.orders.show', $table->activeOccupancy->order) }}">{{ $table->activeOccupancy->order->order_number }}</a>@else—@endif</strong></article>
    </section>
    <section class="panel-card"><h2>Upcoming reservations</h2>@forelse($table->reservations as $reservation)<p><strong>{{ $reservation->starts_at->timezone(config('app.timezone'))->format('M j · H:i') }}</strong> · {{ $reservation->customer_name }} · {{ $reservation->party_size }} guests ({{ $reservation->status }})</p>@empty<p class="muted-copy">No upcoming reservations.</p>@endforelse</section>
    <section class="panel-card"><h2>Recent orders</h2><div class="table-list-shell"><table class="data-table"><thead><tr><th>Order</th><th>Date</th><th>Guests</th><th>Order status</th><th>Total</th></tr></thead><tbody>@forelse($recentOrders as $order)<tr><td><a href="{{ route('restaurant.orders.show', $order) }}">{{ $order->order_number }}</a></td><td>{{ $order->created_at?->timezone(config('app.timezone'))->format('M j, Y H:i') }}</td><td>{{ $order->guest_count ?? '—' }}</td><td>{{ $order->status }}</td><td>₹{{ number_format((float) $order->total, 2) }}</td></tr>@empty<tr><td colspan="5">No historical orders.</td></tr>@endforelse</tbody></table></div></section>
    <div class="table-management-columns">
        <section class="panel-card"><h2>Status history</h2>@forelse($table->statusHistory as $history)<p><strong>{{ $history->from_status ?? 'Created' }} → {{ $history->to_status }}</strong><small>{{ $history->changed_at->timezone(config('app.timezone'))->format('M j, Y H:i') }} · {{ $history->reason }}</small></p>@empty<p>No status changes recorded.</p>@endforelse</section>
        <section class="panel-card"><h2>Transfer history</h2>@forelse($transfers as $transfer)<p><strong>{{ $transfer->source?->table_number }} → {{ $transfer->destination?->table_number }}</strong><small>{{ $transfer->transferred_at->timezone(config('app.timezone'))->format('M j, Y H:i') }} · {{ $transfer->reason }}</small></p>@empty<p>No transfers recorded.</p>@endforelse</section>
    </div>
@endsection
