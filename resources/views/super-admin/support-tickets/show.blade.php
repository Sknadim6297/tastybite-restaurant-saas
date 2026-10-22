@extends('layouts.super-admin')

@section('title', $ticket->ticket_number)
@section('section', 'Support Tickets')

@section('content')
    <div class="page-heading">
        <div><span class="eyebrow">{{ $ticket->ticket_number }} · {{ ucfirst(str_replace('_', ' ', $ticket->category)) }}</span><h1>{{ $ticket->subject }}</h1><p>{{ $ticket->restaurant->name }} · Opened by {{ $ticket->creator->name }}</p></div>
        <a class="button button-secondary" href="{{ route('super-admin.support-tickets.index') }}">Back to tickets</a>
    </div>

    <div class="ticket-detail-grid">
        <section class="panel-card">
            <h2 class="ticket-section-title">Ticket management</h2>
            <form method="POST" action="{{ route('super-admin.support-tickets.update', $ticket) }}" class="form-stack" data-loading-form>
                @csrf
                @method('PUT')
                <div class="form-field"><label for="ticket-status">Status</label><select id="ticket-status" name="status" required>@foreach(['open', 'in_progress', 'waiting_for_restaurant', 'resolved', 'closed'] as $status)<option value="{{ $status }}" @selected($ticket->status === $status)>{{ \App\Support\StatusPresenter::label($status) }}</option>@endforeach</select>@error('status')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="form-field"><label for="ticket-priority">Priority</label><select id="ticket-priority" name="priority" required>@foreach(['low', 'normal', 'high', 'urgent'] as $priority)<option value="{{ $priority }}" @selected($ticket->priority === $priority)>{{ ucfirst($priority) }}</option>@endforeach</select></div>
                <div class="form-field"><label for="ticket-assignee">Assigned administrator</label><select id="ticket-assignee" name="assigned_admin_id"><option value="">Unassigned</option>@foreach($admins as $admin)<option value="{{ $admin->id }}" @selected($ticket->assigned_admin_id === $admin->id)>{{ $admin->name }}</option>@endforeach</select></div>
                <div class="form-field"><label for="ticket-reason">Status change note (optional)</label><input id="ticket-reason" name="reason" maxlength="500"></div>
                <button class="button button-primary" type="submit">Save ticket</button>
            </form>
        </section>

        <section class="panel-card ticket-conversation">
            <div class="section-heading"><div><h2>Conversation</h2><p>Internal notes are visible only to Super Admins.</p></div></div>
            @foreach($ticket->messages as $message)
                <article class="ticket-message {{ $message->visibility === 'internal' ? 'ticket-message-internal' : '' }}">
                    <div class="ticket-message-meta"><strong>{{ $message->authorAdmin?->name ?? $message->authorUser?->name ?? 'Former account' }}</strong><span>{{ $message->visibility === 'internal' ? 'Internal note' : 'Restaurant-visible' }}</span><time>{{ $message->created_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</time></div>
                    <p>{{ $message->body }}</p>
                    @if($message->attachment_path)
                        <a class="button button-secondary" href="{{ route('super-admin.support-tickets.attachments.show', [$ticket, $message]) }}">Download {{ $message->attachment_name }}</a>
                    @endif
                </article>
            @endforeach
            <form method="POST" action="{{ route('super-admin.support-tickets.messages.store', $ticket) }}" enctype="multipart/form-data" class="form-stack ticket-reply-form" data-loading-form>
                @csrf
                <div class="form-field"><label for="message-visibility">Message visibility</label><select id="message-visibility" name="visibility"><option value="public">Reply to restaurant</option><option value="internal">Internal note</option></select></div>
                <div class="form-field"><label for="message-body">Message</label><textarea id="message-body" name="body" rows="5" maxlength="10000" required>{{ old('body') }}</textarea>@error('body')<span class="field-error">{{ $message }}</span>@enderror</div>
                <div class="form-field"><label for="message-attachment">Attachment (JPG, PNG, or PDF; up to 5 MB)</label><input id="message-attachment" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf">@error('attachment')<span class="field-error">{{ $message }}</span>@enderror</div>
                <button class="button button-primary" type="submit">Send message</button>
            </form>
        </section>

        <section class="panel-card ticket-history">
            <h2 class="ticket-section-title">Status history</h2>
            <ol class="ticket-history-list">
                @foreach($ticket->statusHistory as $entry)
                    <li><strong>{{ \App\Support\StatusPresenter::label($entry->previous_status) }} → {{ \App\Support\StatusPresenter::label($entry->new_status) }}</strong><span>{{ $entry->actorAdmin?->name ?? $entry->actorUser?->name ?? 'System' }} · {{ $entry->created_at->timezone(config('app.timezone'))->format('M j, Y H:i') }}</span>@if($entry->reason)<p>{{ $entry->reason }}</p>@endif</li>
                @endforeach
            </ol>
        </section>
    </div>
@endsection
