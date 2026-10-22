@extends('layouts.restaurant-admin')

@section('title', 'Stock Count '.$count->count_number)
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">{{ $count->count_number }} · {{ ucfirst($count->status) }}</span><h1>Physical stock count</h1><p>Enter physical quantities in each item's base unit; posting creates only the required variances.</p></div></div>
    @if($count->status === 'draft')
        <form method="POST" action="{{ route('restaurant.inventory.counts.update', $count->id) }}" class="form-stack">@csrf @method('PUT')
            <section class="panel-card"><div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Expected</th><th>Physical quantity</th><th>Batch</th><th>Manufactured</th><th>Expires</th></tr></thead><tbody>
                @foreach($rows as $index => $row)<tr><td><strong>{{ $row->name }}</strong><small>{{ $row->item_code }}</small></td><td>{{ $row->expected_quantity }} {{ $row->abbreviation }}</td><td><input type="hidden" name="items[{{ $index }}][id]" value="{{ $row->id }}"><input aria-label="Physical quantity for {{ $row->name }}" type="number" min="0" step="0.000001" name="items[{{ $index }}][actual_quantity]" value="{{ old('items.'.$index.'.actual_quantity', $row->actual_quantity) }}" required></td><td>@if($row->track_batches || $row->track_expiry)<input aria-label="Batch number for {{ $row->name }}" name="items[{{ $index }}][batch_number]" maxlength="80" value="{{ old('items.'.$index.'.batch_number', $row->batch_number) }}">@else — @endif</td><td>@if($row->track_batches || $row->track_expiry)<input aria-label="Manufactured date for {{ $row->name }}" type="date" name="items[{{ $index }}][manufactured_at]" value="{{ old('items.'.$index.'.manufactured_at', $row->manufactured_at) }}">@else — @endif</td><td>@if($row->track_batches || $row->track_expiry)<input aria-label="Expiry date for {{ $row->name }}" type="date" name="items[{{ $index }}][expires_at]" value="{{ old('items.'.$index.'.expires_at', $row->expires_at) }}">@else — @endif</td></tr>@endforeach
            </tbody></table></div>@error('items')<span class="field-error">{{ $message }}</span>@enderror
            @foreach($errors->get('items.*.actual_quantity') as $messages)@foreach($messages as $message)<span class="field-error">{{ $message }}</span>@endforeach @endforeach
            </section><div class="form-actions"><button class="button button-secondary" type="submit">Save counted quantities</button></div>
        </form>
        <form method="POST" action="{{ route('restaurant.inventory.counts.post', $count->id) }}" data-confirm="Post all stock count variances? This creates permanent ledger adjustments.">@csrf<button class="button button-primary" type="submit">Approve and post count</button></form>
    @else
        <section class="panel-card"><div class="table-shell"><table class="data-table"><thead><tr><th>Item</th><th>Expected</th><th>Counted</th><th>Variance</th></tr></thead><tbody>
            @foreach($rows as $row)<tr><td>{{ $row->name }}</td><td>{{ $row->expected_quantity }} {{ $row->abbreviation }}</td><td>{{ $row->actual_quantity }}</td><td>{{ \Brick\Math\BigDecimal::of($row->actual_quantity)->minus($row->expected_quantity)->toScale(6)->__toString() }}</td></tr>@endforeach
        </tbody></table></div></section>
    @endif
@endsection
