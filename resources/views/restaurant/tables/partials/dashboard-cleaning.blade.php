<section class="panel-card"><div class="table-section-title"><div><h2>Cleaning queue</h2><p>Return cleaned tables to service when ready.</p></div><span>{{ $tables->where('status', 'cleaning')->count() }} waiting</span></div>
    <div class="table-card-grid table-cleaning-grid">@forelse($tables->where('status', 'cleaning') as $table)
        @php($cleaningEvent = $table->statusHistory->first())
        <article class="table-status-card status-cleaning"><div class="table-card-top"><strong>{{ $table->table_number }}</strong><span class="table-status-badge">Cleaning</span></div><p>{{ $table->floor?->name }} · {{ $table->section?->name ?? 'No section' }} · Seats {{ $table->capacity }}</p>
            @if($cleaningEvent)<p>Waiting since {{ $cleaningEvent->changed_at?->timezone(config('app.timezone'))->format('M j · H:i') }} ({{ $cleaningEvent->changed_at?->diffForHumans(now(), true) }})</p>@endif
            <form method="POST" action="{{ route('restaurant.tables.status', $table) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="available"><button class="button button-primary">Mark clean &amp; available</button></form>
        </article>
    @empty<div class="empty-state"><h2>Cleaning queue is clear</h2><p>Tables marked for cleaning will appear here.</p></div>@endforelse</div>
</section>