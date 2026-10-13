@extends('layouts.restaurant-admin')

@section('title', 'Create Support Ticket')
@section('section', 'Support Tickets')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">RESTAURANT SUPPORT</span><h1>Create a support ticket</h1><p>Describe the issue and include only files needed to help resolve it.</p></div></div>
    <form method="POST" action="{{ route('restaurant.support.store') }}" enctype="multipart/form-data" class="panel-card form-stack ticket-owner-form" data-loading-form>
        @csrf
        <div class="form-field"><label for="ticket-subject">Subject</label><input id="ticket-subject" name="subject" value="{{ old('subject') }}" minlength="4" maxlength="180" required>@error('subject')<span class="field-error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="ticket-category">Category</label><select id="ticket-category" name="category" required>@foreach(['billing' => 'Billing', 'technical' => 'Technical issue', 'account' => 'Account', 'feature_request' => 'Feature request', 'other' => 'Other'] as $value => $label)<option value="{{ $value }}" @selected(old('category') === $value)>{{ $label }}</option>@endforeach</select>@error('category')<span class="field-error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="ticket-body">Describe your request</label><textarea id="ticket-body" name="body" rows="8" minlength="10" maxlength="10000" required>{{ old('body') }}</textarea>@error('body')<span class="field-error">{{ $message }}</span>@enderror</div>
        <div class="form-field"><label for="ticket-attachment">Attachment (JPG, PNG, or PDF; up to 5 MB)</label><input id="ticket-attachment" type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf">@error('attachment')<span class="field-error">{{ $message }}</span>@enderror</div>
        <div class="form-actions"><a class="button button-secondary" href="{{ route('restaurant.support.index') }}">Cancel</a><button class="button button-primary" type="submit">Submit ticket</button></div>
    </form>
@endsection
