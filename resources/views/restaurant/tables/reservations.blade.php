@extends('layouts.restaurant-admin')

@section('title', 'Reservations')
@section('section', 'Table Management')

@section('content')
    @include('restaurant.tables.partials.heading', ['title' => 'Reservations', 'description' => 'Manage guest bookings, table assignments, and availability.'])

    @if(session('status'))
        <div class="notice-card" role="status">{{ session('status') }}</div>
    @endif

    <div class="reservation-page-header">
        <div>
            <div class="table-breadcrumbs">
                <a href="{{ route('restaurant.tables.overview') }}">Table Management</a>
                <span>/</span>
                <span>Reservations</span>
            </div>
        </div>
        <button type="button" class="button button-primary" data-open-reservation-modal>New Reservation</button>
    </div>

    <section class="reservation-kpi-grid" aria-label="Reservation summary">
        @foreach([
            ['Today\'s Reservations', $kpis['today'] ?? 0],
            ['Confirmed', $kpis['confirmed'] ?? 0],
            ['Upcoming', $kpis['upcoming'] ?? 0],
            ['Seated', $kpis['seated'] ?? 0],
        ] as [$label, $value])
            <article class="reservation-kpi-card">
                <small>{{ $label }}</small>
                <strong>{{ number_format((int) $value) }}</strong>
            </article>
        @endforeach
    </section>

    <nav class="reservation-view-switch" aria-label="Reservation views">
        <a href="{{ route('restaurant.tables.reservations', ['view' => 'list'] + request()->query()) }}" @class(['is-active' => ($viewMode ?? 'list') === 'list'])>List View</a>
        <a href="{{ route('restaurant.tables.reservations', ['view' => 'calendar'] + request()->query()) }}" @class(['is-active' => ($viewMode ?? 'list') === 'calendar'])>Calendar View</a>
    </nav>

    <form method="GET" action="{{ route('restaurant.tables.reservations') }}" class="panel-card reservation-filter-toolbar" data-table-filter>
        <input type="hidden" name="view" value="{{ $viewMode ?? 'list' }}">
        <input type="hidden" name="period" value="{{ $calendarPeriod }}">
        <div class="reservation-filter-grid">
            <label class="table-filter-field reservation-filter-search">
                <span class="table-filter-label">Search</span>
                <span class="table-search-wrap">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" name="search" value="{{ request('search') }}" placeholder="Guest, phone or reference" data-debounce>
                </span>
            </label>

            <label class="table-filter-field">
                <span class="table-filter-label">Status</span>
                <select name="status" data-auto-submit>
                    <option value="">All statuses</option>
                    @foreach(['pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ str($status)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </label>

            <label class="table-filter-field">
                <span class="table-filter-label">Floor</span>
                <select name="floor" data-auto-submit>
                    <option value="">All floors</option>
                    @foreach($floors as $floor)
                        <option value="{{ $floor->id }}" @selected(request('floor') == $floor->id)>{{ $floor->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="table-filter-field">
                <span class="table-filter-label">Table</span>
                <select name="table" data-auto-submit>
                    <option value="">All tables</option>
                    @foreach($tables as $table)
                        <option value="{{ $table->id }}" @selected(request('table') == $table->id)>{{ $table->table_number }}</option>
                    @endforeach
                </select>
            </label>

            <label class="table-filter-field reservation-filter-date">
                <span class="table-filter-label">Date</span>
                <input type="date" name="date" value="{{ $calendarDate }}" data-auto-submit>
            </label>

            <div class="reservation-filter-actions">
                <span class="table-loading" role="status" hidden>Updating…</span>
                <a class="button button-secondary" href="{{ route('restaurant.tables.reservations', ['view' => $viewMode ?? 'list']) }}">Clear</a>
            </div>
        </div>
    </form>

    @if($viewMode === 'calendar')
        <section class="panel-card reservation-calendar-shell">
            @php
                $calendarAnchor = Carbon\CarbonImmutable::parse($calendarDate, config('app.timezone'));
                $calendarPreviousDate = match ($calendarPeriod) {
                    'week' => $calendarAnchor->subWeek()->toDateString(),
                    'month' => $calendarAnchor->subMonthNoOverflow()->toDateString(),
                    default => $calendarAnchor->subDay()->toDateString(),
                };
                $calendarNextDate = match ($calendarPeriod) {
                    'week' => $calendarAnchor->addWeek()->toDateString(),
                    'month' => $calendarAnchor->addMonthNoOverflow()->toDateString(),
                    default => $calendarAnchor->addDay()->toDateString(),
                };
                $calendarPeriodLabel = match ($calendarPeriod) {
                    'week' => $calendarAnchor->startOfWeek()->translatedFormat('M j').' – '.$calendarAnchor->endOfWeek()->translatedFormat('M j, Y'),
                    'month' => $calendarAnchor->translatedFormat('F Y'),
                    default => $calendarAnchor->translatedFormat('D, M j, Y'),
                };
            @endphp
            <div class="reservation-calendar-header">
                <div class="reservation-calendar-nav">
                    <a class="button button-secondary" href="{{ route('restaurant.tables.reservations', ['view' => 'calendar', 'period' => $calendarPeriod, 'date' => $calendarPreviousDate] + request()->except(['period','date','view']) ) }}">Previous</a>
                    <a class="button button-secondary" href="{{ route('restaurant.tables.reservations', ['view' => 'calendar', 'period' => $calendarPeriod, 'date' => now(config('app.timezone'))->toDateString()] + request()->except(['period','date','view']) ) }}">Today</a>
                    <a class="button button-secondary" href="{{ route('restaurant.tables.reservations', ['view' => 'calendar', 'period' => $calendarPeriod, 'date' => $calendarNextDate] + request()->except(['period','date','view']) ) }}">Next</a>
                </div>
                <strong>{{ $calendarPeriodLabel }}</strong>
                <div class="reservation-calendar-periods">
                    @foreach(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $periodKey => $periodLabel)
                        <a href="{{ route('restaurant.tables.reservations', ['view' => 'calendar', 'period' => $periodKey, 'date' => $calendarDate] + request()->except(['period','date','view']) ) }}" @class(['is-active' => $calendarPeriod === $periodKey])>{{ $periodLabel }}</a>
                    @endforeach
                </div>
            </div>

            <div class="reservation-calendar-grid">
                @forelse($reservations as $reservation)
                    <article class="reservation-calendar-card reservation-status-{{ str_replace('_', '-', $reservation->status) }}">
                        <div class="reservation-calendar-top">
                            <strong>{{ $reservation->customer_name }}</strong>
                            <span class="reservation-status-badge status-{{ str_replace('_', '-', $reservation->status) }}">{{ str($reservation->status)->replace('_', ' ')->title() }}</span>
                        </div>
                        <p>{{ $reservation->starts_at?->timezone(config('app.timezone'))->format('g:i A') }} · {{ $reservation->party_size }} guests</p>
                        <small>{{ $reservation->table?->table_number ?? 'Unassigned' }} · {{ $reservation->floor?->name ?? 'Any floor' }}</small>
                    </article>
                @empty
                    <div class="panel-card empty-state reservation-empty-state">
                        <div class="reservation-empty-icon" aria-hidden="true"><i class="bi bi-calendar3"></i></div>
                        <h2>{{ $hasAnyReservations ? 'No matching reservations' : 'No reservations yet' }}</h2>
                        <p>{{ $hasAnyReservations ? 'Try adjusting your search or filters.' : 'Create your first reservation to start managing guest bookings.' }}</p>
                        @if($hasAnyReservations)
                            <a class="button button-primary" href="{{ route('restaurant.tables.reservations', ['view' => $viewMode ?? 'list']) }}">Clear Filters</a>
                        @else
                            <button type="button" class="button button-primary" data-open-reservation-modal>New Reservation</button>
                        @endif
                    </div>
                @endforelse
            </div>
        </section>
    @else
        <section class="panel-card reservation-table-panel">
            <div class="reservation-table-shell">
                @if($reservations->total() > 0)
                    <div class="reservation-results-summary">
                        Showing {{ $reservations->firstItem() }}–{{ $reservations->lastItem() }} of {{ $reservations->total() }} results
                    </div>
                <table class="data-table reservation-table">
                    <thead>
                        <tr>
                            <th>Reference / Guest</th>
                            <th>Reservation</th>
                            <th>Party</th>
                            <th>Floor / Table</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($reservations as $reservation)
                            <tr>
                                <td>
                                    <strong>{{ $reservation->reference }}</strong>
                                    <small>{{ $reservation->customer_name }} · {{ $reservation->phone }}</small>
                                </td>
                                <td>
                                    {{ $reservation->starts_at?->timezone(config('app.timezone'))->format('M j, Y · H:i') }}
                                    <small>{{ $reservation->duration_minutes }} minutes</small>
                                </td>
                                <td>{{ $reservation->party_size }}</td>
                                <td>
                                    {{ $reservation->floor?->name ?? 'Any floor' }}
                                    <small>{{ $reservation->table?->table_number ?? 'Unassigned' }}</small>
                                </td>
                                <td>
                                    <span class="reservation-status-badge status-{{ str_replace('_', '-', $reservation->status) }}">{{ str($reservation->status)->replace('_', ' ')->title() }}</span>
                                </td>
                                <td>
                                    <div class="reservation-action-stack">
                                        @if(! in_array($reservation->status, ['completed', 'cancelled', 'no_show'], true))
                                            <details class="reservation-inline-details">
                                                <summary class="button button-secondary">Edit</summary>
                                                <div class="reservation-inline-form-panel">
                                                    <form method="POST" action="{{ route('restaurant.tables.reservations.update', $reservation) }}" class="reservation-inline-form">
                                                        @csrf
                                                        @method('PUT')
                                                        <label class="table-form-field">
                                                            <span>Name</span>
                                                            <input name="customer_name" value="{{ $reservation->customer_name }}" required>
                                                        </label>
                                                        <label class="table-form-field">
                                                            <span>Phone</span>
                                                            <input name="phone" value="{{ $reservation->phone }}" required>
                                                        </label>
                                                        <label class="table-form-field">
                                                            <span>Email</span>
                                                            <input type="email" name="email" value="{{ $reservation->email }}">
                                                        </label>
                                                        <label class="table-form-field">
                                                            <span>Date / time</span>
                                                            <input type="datetime-local" name="starts_at" value="{{ $reservation->starts_at?->timezone(config('app.timezone'))->format('Y-m-d\TH:i') }}" required>
                                                        </label>
                                                        <label class="table-form-field">
                                                            <span>Duration (min)</span>
                                                            <input type="number" name="duration_minutes" min="15" max="1440" value="{{ $reservation->duration_minutes }}" required>
                                                        </label>
                                                        <label class="table-form-field">
                                                            <span>Party size</span>
                                                            <input type="number" name="party_size" min="1" value="{{ $reservation->party_size }}" required>
                                                        </label>
                                                        <label class="table-form-field">
                                                            <span>Floor</span>
                                                            <select name="floor_id">
                                                                <option value="">Any floor</option>
                                                                @foreach($floors as $floor)
                                                                    <option value="{{ $floor->id }}" @selected($reservation->floor_id === $floor->id)>{{ $floor->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <label class="table-form-field">
                                                            <span>Table</span>
                                                            <select name="table_id">
                                                                <option value="">Unassigned</option>
                                                                @foreach($tables as $table)
                                                                    <option value="{{ $table->id }}" @selected($reservation->table_id === $table->id)>{{ $table->table_number }} · {{ $table->floor?->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <label class="table-form-field table-form-field-full">
                                                            <span>Special requests</span>
                                                            <textarea name="special_requests">{{ $reservation->special_requests }}</textarea>
                                                        </label>
                                                        <label class="table-form-field table-form-field-full">
                                                            <span>Internal notes</span>
                                                            <textarea name="internal_notes">{{ $reservation->internal_notes }}</textarea>
                                                        </label>
                                                        <div class="reservation-inline-actions">
                                                            <button type="button" class="button button-secondary" data-collapse-inline-reservation>Cancel</button>
                                                            <button class="button button-primary" type="submit">Save changes</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </details>
                                        @endif

                                        @foreach(match($reservation->status) { 'pending' => ['confirmed' => 'Confirm', 'cancelled' => 'Cancel', 'no_show' => 'No show'], 'confirmed' => ['seated' => 'Seat guest', 'cancelled' => 'Cancel', 'no_show' => 'No show'], 'seated' => ['completed' => 'Complete'], default => [] } as $next => $label)
                                            <form method="POST" action="{{ route('restaurant.tables.reservations.status', $reservation) }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="status" value="{{ $next }}">
                                                <button class="button button-secondary" type="submit">{{ $label }}</button>
                                            </form>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @else
                    <div class="empty-state reservation-empty-state">
                        <div class="reservation-empty-icon" aria-hidden="true"><i class="bi bi-calendar3"></i></div>
                        <h2>{{ $hasAnyReservations ? 'No matching reservations' : 'No reservations yet' }}</h2>
                        <p>{{ $hasAnyReservations ? 'Try adjusting your search or filters.' : 'Create your first reservation to start managing guest bookings.' }}</p>
                        @if($hasAnyReservations)
                            <a class="button button-primary" href="{{ route('restaurant.tables.reservations', ['view' => $viewMode ?? 'list']) }}">Clear Filters</a>
                        @else
                            <button type="button" class="button button-primary" data-open-reservation-modal>New Reservation</button>
                        @endif
                    </div>
                @endif
            </div>
            @if($reservations->total() > 0 && $reservations->lastPage() > 1)
                <div class="table-pagination">{{ $reservations->links() }}</div>
            @endif
        </section>
    @endif

    <div class="reservation-modal {{ $errors->any() ? 'is-open' : '' }}" id="reservation-modal" aria-hidden="{{ $errors->any() ? 'false' : 'true' }}">
        <div class="reservation-modal-backdrop" data-close-reservation-modal></div>
        <div class="reservation-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="reservation-modal-title">
            <div class="reservation-modal-header">
                <div>
                    <h2 id="reservation-modal-title">New Reservation</h2>
                    <p>Enter guest details and select an available table.</p>
                </div>
                <button type="button" class="button button-secondary" data-close-reservation-modal aria-label="Close">Close</button>
            </div>

            <form method="POST" action="{{ route('restaurant.tables.reservations.store') }}" class="reservation-modal-form">
                @csrf
                <div class="reservation-form-grid">
                    <label class="table-form-field">
                        <span>Customer name</span>
                        <input name="customer_name" maxlength="160" value="{{ old('customer_name') }}" required>
                        @error('customer_name')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="table-form-field">
                        <span>Phone number</span>
                        <input name="phone" maxlength="32" value="{{ old('phone') }}" required>
                        @error('phone')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="table-form-field">
                        <span>Email</span>
                        <input type="email" name="email" maxlength="190" value="{{ old('email') }}">
                        @error('email')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="table-form-field table-form-field-full">
                        <span>Reservation date &amp; time</span>
                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', now(config('app.timezone'))->format('Y-m-d\TH:i')) }}" required>
                        @error('starts_at')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="table-form-field">
                        <span>Expected duration (minutes)</span>
                        <input type="number" name="duration_minutes" min="15" max="1440" value="{{ old('duration_minutes', 120) }}" required>
                        @error('duration_minutes')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="table-form-field">
                        <span>Party size</span>
                        <input type="number" name="party_size" min="1" max="1000" value="{{ old('party_size', 2) }}" required>
                        @error('party_size')<small class="field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="table-form-field">
                        <span>Preferred floor</span>
                        <select name="floor_id">
                            <option value="">Any floor</option>
                            @foreach($floors as $floor)
                                <option value="{{ $floor->id }}" @selected(old('floor_id') == $floor->id)>{{ $floor->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="table-form-field">
                        <span>Table</span>
                        <select name="table_id">
                            <option value="">Unassigned</option>
                            @foreach($tables as $table)
                                <option value="{{ $table->id }}" @selected(old('table_id') == $table->id)>{{ $table->table_number }} · {{ $table->floor?->name }} · seats {{ $table->capacity }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="table-form-field table-form-field-full">
                        <span>Special requests</span>
                        <textarea name="special_requests" rows="2" maxlength="2000">{{ old('special_requests') }}</textarea>
                    </label>
                    <label class="table-form-field table-form-field-full">
                        <span>Internal notes</span>
                        <textarea name="internal_notes" rows="2" maxlength="2000">{{ old('internal_notes') }}</textarea>
                    </label>
                </div>

                <div class="reservation-modal-footer">
                    <button type="button" class="button button-secondary" data-close-reservation-modal>Cancel</button>
                    <button type="submit" class="button button-primary">Save Reservation</button>
                </div>
            </form>
        </div>
    </div>

    <script src="{{ asset('assets/table-filters.js') }}?v={{ filemtime(public_path('assets/table-filters.js')) }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('reservation-modal');
            if (!modal) return;
            const openButtons = document.querySelectorAll('[data-open-reservation-modal]');
            const closeButtons = document.querySelectorAll('[data-close-reservation-modal]');

            const setModalState = (open) => {
                modal.classList.toggle('is-open', open);
                modal.setAttribute('aria-hidden', open ? 'false' : 'true');
            };

            openButtons.forEach(button => {
                button.addEventListener('click', () => setModalState(true));
            });
            closeButtons.forEach(button => {
                button.addEventListener('click', () => setModalState(false));
            });

            if (modal.classList.contains('is-open')) {
                setModalState(true);
            }
        });
    </script>
@endsection
