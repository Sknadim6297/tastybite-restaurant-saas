@extends('layouts.restaurant-admin')

@section('title', $ticket->ticket_number)
@section('section', 'Support Tickets')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ $ticket->ticket_number }} · {{ \App\Support\StatusPresenter::label($ticket->category) }}</span><h1>{{ $ticket->subject }}</h1><p>Status: {{ \App\Support\StatusPresenter::label($ticket->status) }} · Priority: {{ ucfirst($ticket->priority) }}</p></div><a class="button button-secondary" href="{{ route('restaurant.support.index') }}">Back to support</a></div>

    <section class="panel-card ticket-conversation">
        <div class="section-heading"><div><h2>Conversation</h2><p>Messages and attachments are private to your restaurant and support administrators.</p></div></div>
        @foreach($ticket->messages as $message)
            <article class="ticket-message">
                <div class="ticket-message-meta"><strong>{{ $message->authorAdmin?->name ?? $message->authorUser?->name ?? 'Former account' }}</strong><span>Support conversation</span><time>{{ $message->created_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</time></div>
                <p>{{ $message->body }}</p>
                @if($message->attachment_path)<a class="button button-secondary" href="{{ route('restaurant.support.attachments.show', [$ticket, $message]) }}">Download {{ $message->attachment_name }}</a>@endif
            </article>
        @endforeach

        @if($ticket->status !== 'closed')
            <form method="POST" action="{{ route('restaurant.support.messages.store', $ticket) }}" enctype="multipart/form-data" class="form-stack ticket-reply-form" data-loading-form>
                @csrf
                <div class="form-field"><label for="owner-reply">Reply</label><textarea id="owner-reply" name="body" rows="5" maxlength="10000" required>{{ old('body') }}</textarea>@error('body')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="form-field"><label for="owner-attachment">Attachment (JPG, PNG, or PDF; up to 5 MB)</label><input id="owner-attachment" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf">@error('attachment')<span class="field-error">{{ $message }}</span>@enderror</div>
                <button class="button button-primary" type="submit">Send reply</button>
            </form>
        @else
            <div class="notice-card"><strong>This ticket is closed.</strong><p>Create a new ticket if you need additional assistance.</p></div>
        @endif
    </section>

    <section class="panel-card ticket-history">
        <h2 class="ticket-section-title">Status history</h2>
        <ol class="ticket-history-list">
            @foreach($ticket->statusHistory as $entry)
                <li><strong>{{ \App\Support\StatusPresenter::label($entry->previous_status) }} → {{ \App\Support\StatusPresenter::label($entry->new_status) }}</strong><span>{{ $entry->actorAdmin?->name ?? $entry->actorUser?->name ?? 'System' }} · {{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span>@if($entry->reason)<p>{{ $entry->reason }}</p>@endif</li>
            @endforeach
        </ol>
    </section>
@endsection
