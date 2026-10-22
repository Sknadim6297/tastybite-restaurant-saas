@extends('layouts.restaurant-admin')

@section('title', ucfirst($kind).' Stock')
@section('section', 'Inventory Management')

@section('content')
    <div class="page-heading"><div><span class="eyebrow">Audited stock movement</span><h1>{{ ['opening' => 'Opening stock', 'in' => 'Stock in', 'out' => 'Stock out', 'transfer' => 'Stock transfer', 'adjustment' => 'Stock adjustment', 'wastage' => 'Record wastage'][$kind] }}</h1><p>All quantities are converted to the item's base unit and recorded in the immutable ledger.</p></div></div>
    @if($kind === 'in')
        <form id="stock-receipt-form" method="POST" action="{{ route('restaurant.inventory.stock.in') }}" class="form-stack" data-stock-receipt-form data-disable-on-submit>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="stock-receipt-layout">
                <div class="stock-receipt-sections">
                    <section class="panel-card form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-box-seam"></i></span><div><h2>Stock information</h2><p>Choose the item, destination, and receipt quantity.</p></div></div>
                        <div class="form-grid">
                            <div class="form-field"><label for="item">Inventory item <span class="required-mark" aria-hidden="true">*</span></label><select id="item" name="inventory_item_id" required data-stock-item>@foreach($items as $item)<option value="{{ $item->id }}" data-track-batches="{{ $item->track_batches ? '1' : '0' }}" data-track-expiry="{{ $item->track_expiry ? '1' : '0' }}" data-base-unit="{{ $item->baseUnit->abbreviation }}" @selected(old('inventory_item_id') == $item->id)>{{ $item->item_code }} · {{ $item->name }} ({{ $item->baseUnit->abbreviation }})</option>@endforeach</select>@error('inventory_item_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field"><label for="location">Stock location <span class="required-mark" aria-hidden="true">*</span></label><select id="location" name="location_id" required data-stock-location>@foreach($locations as $location)<option value="{{ $location->id }}" @selected(old('location_id') == $location->id)>{{ $location->name }}</option>@endforeach</select>@error('location_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field"><label for="unit">Unit <span class="required-mark" aria-hidden="true">*</span></label><select id="unit" name="unit_id" required data-stock-unit>@foreach($units as $unit)<option value="{{ $unit->id }}" data-abbreviation="{{ $unit->abbreviation }}" @selected(old('unit_id') == $unit->id)>{{ $unit->name }} ({{ $unit->abbreviation }})</option>@endforeach</select>@error('unit_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field"><label for="quantity">Quantity <span class="required-mark" aria-hidden="true">*</span></label><input id="quantity" type="number" min="0.000001" step="0.000001" name="quantity" value="{{ old('quantity') }}" required inputmode="decimal" data-stock-quantity>@error('quantity')<span class="field-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field"><label for="unit_cost">Unit cost (per selected unit) <span class="required-mark" aria-hidden="true">*</span></label><div class="input-prefix-wrap"><span aria-hidden="true">₹</span><input id="unit_cost" name="unit_cost" type="number" min="0" step="0.000001" value="{{ old('unit_cost', '0') }}" required inputmode="decimal" data-stock-unit-cost></div>@error('unit_cost')<span class="field-error">{{ $message }}</span>@enderror</div>
                        </div>
                    </section>
                    <section class="panel-card form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-truck"></i></span><div><h2>Supplier and batch details</h2><p>Supplier is optional. Lot details appear when tracking is enabled for the selected item.</p></div></div>
                        <div class="form-grid">
                            <div class="form-field"><label for="vendor_id">Vendor <span class="field-optional">Optional</span></label><select id="vendor_id" name="vendor_id"><option value="">Select vendor</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected(old('vendor_id') == $vendor->id)>{{ $vendor->name }}</option>@endforeach</select>@error('vendor_id')<span class="field-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field stock-tracking-field" data-stock-batch-field hidden><label for="batch_number">Batch number <span class="required-mark" aria-hidden="true">*</span></label><input id="batch_number" name="batch_number" maxlength="80" value="{{ old('batch_number') }}" data-stock-batch-number>@error('batch_number')<span class="field-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field stock-tracking-field" data-stock-manufactured-field hidden><label for="manufactured_at">Manufacturing date <span class="field-optional">Optional</span></label><input id="manufactured_at" name="manufactured_at" type="date" value="{{ old('manufactured_at') }}" data-stock-manufactured></div>
                            <div class="form-field stock-tracking-field" data-stock-expiry-field hidden><label for="expires_at">Expiry date <span class="field-optional">Optional</span></label><input id="expires_at" name="expires_at" type="date" value="{{ old('expires_at') }}" data-stock-expiry>@error('expires_at')<span class="field-error">{{ $message }}</span>@enderror</div>
                        </div>
                    </section>
                    <section class="panel-card form-section">
                        <div class="form-section-heading"><span class="form-section-icon"><i class="bi bi-calendar2-check"></i></span><div><h2>Transaction information</h2><p>Add a date and reference to make this receipt easy to trace.</p></div></div>
                        <div class="form-grid">
                            <div class="form-field"><label for="occurred_at">Transaction date <span class="required-mark" aria-hidden="true">*</span></label><input id="occurred_at" type="datetime-local" name="occurred_at" value="{{ old('occurred_at', now()->format('Y-m-d\TH:i')) }}" required>@error('occurred_at')<span class="field-error">{{ $message }}</span>@enderror</div>
                            <div class="form-field"><label for="reference">Reference <span class="field-optional">Optional</span></label><input id="reference" name="reference" maxlength="120" value="{{ old('reference') }}" placeholder="Invoice or delivery reference"></div>
                            <div class="form-field form-field-full"><label for="notes">Notes <span class="field-optional">Optional</span></label><textarea id="notes" name="notes" rows="3" maxlength="2000" placeholder="Add any useful receiving notes">{{ old('notes') }}</textarea></div>
                        </div>
                    </section>
                </div>
                <aside class="panel-card stock-receipt-summary" aria-live="polite">
                    <span class="eyebrow">RECEIPT SUMMARY</span>
                    <h2>Review stock in</h2>
                    <dl>
                        <div><dt>Item</dt><dd data-summary-item>—</dd></div>
                        <div><dt>Quantity</dt><dd data-summary-quantity>—</dd></div>
                        <div><dt>Unit cost</dt><dd data-summary-unit-cost>₹0.00</dd></div>
                        <div><dt>Receiving location</dt><dd data-summary-location>—</dd></div>
                    </dl>
                    <div class="summary-total"><span>Estimated total value</span><strong data-summary-total>₹0.00</strong></div>
                    <p class="summary-disclaimer">Estimate only. Final quantity conversion and valuation are calculated and validated when posted.</p>
                </aside>
            </div>
            <div class="form-actions"><a class="button button-secondary" href="{{ route('restaurant.inventory.overview') }}">Cancel</a><button class="button button-primary" type="submit" data-submit-label="Posting receipt...">Post receipt</button></div>
        </form>
    @elseif($kind === 'transfer')
        <form method="POST" action="{{ route('restaurant.inventory.stock.transfers.store') }}" class="form-stack"><section class="panel-card"><div class="form-grid">@csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="form-field"><label for="item">Item</label><select id="item" name="inventory_item_id" required>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->item_code }} · {{ $item->name }} ({{ $item->baseUnit->abbreviation }})</option>@endforeach</select></div>
            <div class="form-field"><label for="source">Source location</label><select id="source" name="source_location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="destination">Destination location</label><select id="destination" name="destination_location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="quantity">Quantity</label><input id="quantity" type="number" min="0.000001" step="0.000001" name="quantity" required></div>
            <div class="form-field"><label for="occurred_at">Transfer date</label><input id="occurred_at" type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>
            <div class="form-field form-field-full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3"></textarea></div>
        </div>@foreach(['inventory_item_id', 'source_location_id', 'destination_location_id', 'quantity', 'occurred_at'] as $field)@error($field)<span class="field-error">{{ $message }}</span>@enderror @endforeach</section><div class="form-actions"><a class="button button-secondary" href="{{ route('restaurant.inventory.overview') }}">Cancel</a><button class="button button-primary" type="submit">Post transfer</button></div></form>
    @else
        @php
            $action = match ($kind) {
                'opening' => route('restaurant.inventory.stock.opening'),
                'in' => route('restaurant.inventory.stock.in'),
                'out' => route('restaurant.inventory.stock.out'),
                'adjustment' => route('restaurant.inventory.stock.adjustments.store'),
                'wastage' => route('restaurant.inventory.wastage.store'),
            };
        @endphp
        <form method="POST" action="{{ $action }}" class="form-stack"><section class="panel-card"><div class="form-grid">@csrf
            <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="form-field"><label for="item">Item</label><select id="item" name="inventory_item_id" required>@foreach($items as $item)<option value="{{ $item->id }}">{{ $item->item_code }} · {{ $item->name }} ({{ $item->baseUnit->abbreviation }})</option>@endforeach</select></div>
            <div class="form-field"><label for="location">Location</label><select id="location" name="location_id" required>@foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach</select></div>
            @if(in_array($kind, ['opening', 'in', 'out'], true))
                <div class="form-field"><label for="unit">Unit</label><select id="unit" name="unit_id" required>@foreach($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>@endforeach</select></div>
                <div class="form-field"><label for="quantity">Quantity</label><input id="quantity" type="number" min="0.000001" step="0.000001" name="quantity" required></div>
            @endif
            @if(in_array($kind, ['opening', 'in'], true))
                <div class="form-field"><label for="unit_cost">Unit cost (per selected unit)</label><input id="unit_cost" name="unit_cost" type="number" min="0" step="0.000001" value="0" required></div>
                <div class="form-field"><label for="vendor_id">Vendor (optional)</label><select id="vendor_id" name="vendor_id"><option value="">None</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->name }}</option>@endforeach</select></div>
                <div class="form-field"><label for="batch_number">Batch number <small>required if batch/expiry tracking is enabled</small></label><input id="batch_number" name="batch_number" maxlength="80"></div>
                <div class="form-field"><label for="manufactured_at">Manufacturing date</label><input id="manufactured_at" name="manufactured_at" type="date"></div>
                <div class="form-field"><label for="expires_at">Expiry date</label><input id="expires_at" name="expires_at" type="date"></div>
            @endif
            @if($kind === 'out')
                <div class="form-field"><label for="batch_id">Batch (optional)</label><select id="batch_id" name="batch_id"><option value="">FEFO automatic selection</option>@foreach($batches as $batch)<option value="{{ $batch->id }}">Batch {{ $batch->batch_number }} · {{ $batch->available_quantity }}</option>@endforeach</select></div>
                <div class="form-field"><label for="reason">Reason</label><select id="reason" name="reason" required><option value="kitchen_consumption">Kitchen consumption</option><option value="internal_use">Internal use</option><option value="damage">Damage</option><option value="return_to_vendor">Return to vendor</option><option value="other">Other</option></select></div>
            @endif
            @if($kind === 'adjustment')
                <div class="form-field"><label for="actual_quantity">Actual quantity (base unit)</label><input id="actual_quantity" name="actual_quantity" type="number" min="0" step="0.000001" required></div>
                <div class="form-field"><label for="batch_number">Batch number <small>required for positive adjustments to tracked items</small></label><input id="batch_number" name="batch_number" maxlength="80"></div>
                <div class="form-field"><label for="manufactured_at">Manufacturing date</label><input id="manufactured_at" name="manufactured_at" type="date"></div>
                <div class="form-field"><label for="expires_at">Expiry date</label><input id="expires_at" name="expires_at" type="date"></div>
                <div class="form-field"><label for="reason">Reason</label><select id="reason" name="reason" required><option value="physical_count">Physical count difference</option><option value="damage">Damage</option><option value="data_correction">Data correction</option><option value="other">Other</option></select></div>
            @endif
            @if($kind === 'wastage')
                <div class="form-field"><label for="quantity">Wasted quantity (base unit)</label><input id="quantity" name="quantity" type="number" min="0.000001" step="0.000001" required></div>
                <div class="form-field"><label for="reason">Reason</label><select id="reason" name="reason" required><option value="expired">Expired</option><option value="spoiled">Spoiled</option><option value="damaged">Damaged</option><option value="overcooked">Overcooked</option><option value="preparation_waste">Preparation waste</option><option value="other">Other</option></select></div>
                <div class="form-field"><label for="batch_id">Batch (optional)</label><select id="batch_id" name="batch_id"><option value="">FEFO automatic selection</option>@foreach($batches as $batch)<option value="{{ $batch->id }}">{{ $batch->batch_number }}</option>@endforeach</select></div>
            @endif
            @if(in_array($kind, ['opening', 'in', 'out'], true))<div class="form-field"><label for="occurred_at">Date</label><input id="occurred_at" type="datetime-local" name="occurred_at" value="{{ now()->format('Y-m-d\TH:i') }}" required></div>@endif
            <div class="form-field"><label for="reference">Reference</label><input id="reference" name="reference" maxlength="120"></div>
            <div class="form-field form-field-full"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" maxlength="2000"></textarea></div>
        </div>
        @foreach(['inventory_item_id', 'location_id', 'unit_id', 'quantity', 'unit_cost', 'actual_quantity', 'reason', 'occurred_at', 'batch_id'] as $field)@error($field)<span class="field-error">{{ $message }}</span>@enderror @endforeach
        </section><div class="form-actions"><a class="button button-secondary" href="{{ route('restaurant.inventory.overview') }}">Cancel</a><button class="button button-primary" type="submit">Post {{ $kind === 'in' ? 'receipt' : ($kind === 'out' ? 'issue' : $kind) }}</button></div></form>
    @endif
    @if($kind === 'in')
        <script src="{{ asset('assets/restaurant-inventory.js') }}?v={{ filemtime(public_path('assets/restaurant-inventory.js')) }}" defer></script>
    @endif
@endsection
