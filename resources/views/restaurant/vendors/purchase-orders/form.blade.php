@extends('layouts.restaurant-admin')

@section('title', $order->exists ? 'Edit Purchase Order' : 'Create Purchase Order')
@section('section', 'Vendor Management')

@section('content')
    @php($formItems = old('items', $items->isNotEmpty() ? $items->map(fn ($item) => $item->only(['item_name','quantity','unit','unit_price','discount_amount','tax_rate']))->values()->all() : [[]]))
    <div class="page-heading"><div><span class="eyebrow">PURCHASING</span><h1>{{ $order->exists ? 'Edit draft order' : 'Create purchase order' }}</h1><p>Totals are recalculated and validated by the server.</p></div></div>
    <form method="POST" action="{{ $order->exists ? route('restaurant.vendors.purchase-orders.update', $order) : route('restaurant.vendors.purchase-orders.store') }}" class="form-stack" data-purchase-order-form>
        @csrf @if($order->exists) @method('PUT') @endif
        <section class="panel-card"><div class="form-grid">
            <div class="form-field"><label for="po-vendor">Vendor</label><select id="po-vendor" name="vendor_id" required><option value="">Select vendor</option>@foreach($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $selectedVendorId) === (string) $vendor->id)>{{ $vendor->name }} · {{ $vendor->vendor_code }}</option>@endforeach</select>@error('vendor_id')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="po-date">Order date</label><input id="po-date" type="date" name="order_date" value="{{ old('order_date', $order->order_date?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" required>@error('order_date')<span class="field-error">{{ $message }}</span>@enderror</div>
            <div class="form-field"><label for="po-expected">Expected delivery date</label><input id="po-expected" type="date" name="expected_delivery_date" value="{{ old('expected_delivery_date', $order->expected_delivery_date?->format('Y-m-d')) }}">@error('expected_delivery_date')<span class="field-error">{{ $message }}</span>@enderror</div>
        </div></section>
        <div class="section-heading"><div><h2>Item details</h2><p>Enter each item as a separate line.</p></div><button type="button" class="button button-secondary" data-add-po-item>Add item</button></div>
        <div class="vendor-line-items" data-po-items>
            @foreach($formItems as $index => $item)
                <div class="vendor-line-item" data-po-item>
                    <div class="vendor-line-item-grid">
                        <div class="form-field"><label>Item</label><input name="items[{{ $index }}][item_name]" value="{{ $item['item_name'] ?? '' }}" maxlength="180" required></div>
                        <div class="form-field"><label>Quantity</label><input name="items[{{ $index }}][quantity]" type="number" step="0.001" min="0.001" value="{{ $item['quantity'] ?? '' }}" required></div>
                        <div class="form-field"><label>Unit</label><input name="items[{{ $index }}][unit]" value="{{ $item['unit'] ?? 'unit' }}" maxlength="32" required></div>
                        <div class="form-field"><label>Unit price</label><input name="items[{{ $index }}][unit_price]" type="number" step="0.01" min="0" value="{{ $item['unit_price'] ?? '' }}" required></div>
                        <div class="form-field"><label>Discount</label><input name="items[{{ $index }}][discount_amount]" type="number" step="0.01" min="0" value="{{ $item['discount_amount'] ?? '0.00' }}"></div>
                        <div class="form-field"><label>Tax rate (%)</label><input name="items[{{ $index }}][tax_rate]" type="number" step="0.01" min="0" max="100" value="{{ $item['tax_rate'] ?? '0.00' }}"></div>
                    </div>
                    <button type="button" class="button button-secondary" data-remove-po-item>Remove item</button>
                </div>
            @endforeach
        </div>
        @error('items')<span class="field-error">{{ $message }}</span>@enderror
        <template data-po-item-template><div class="vendor-line-item" data-po-item><div class="vendor-line-item-grid">
            <div class="form-field"><label>Item</label><input data-field="item_name" maxlength="180" required></div><div class="form-field"><label>Quantity</label><input data-field="quantity" type="number" step="0.001" min="0.001" required></div><div class="form-field"><label>Unit</label><input data-field="unit" value="unit" maxlength="32" required></div><div class="form-field"><label>Unit price</label><input data-field="unit_price" type="number" step="0.01" min="0" required></div><div class="form-field"><label>Discount</label><input data-field="discount_amount" type="number" step="0.01" min="0" value="0.00"></div><div class="form-field"><label>Tax rate (%)</label><input data-field="tax_rate" type="number" step="0.01" min="0" max="100" value="0.00"></div>
        </div><button type="button" class="button button-secondary" data-remove-po-item>Remove item</button></div></template>
        <div class="form-field"><label for="po-notes">Notes</label><textarea id="po-notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $order->notes) }}</textarea>@error('notes')<span class="field-error">{{ $message }}</span>@enderror</div>
        <section class="summary-row report-summary"><article class="summary-tile"><span>Subtotal preview</span><strong>INR <span data-po-subtotal>0.00</span></strong></article><article class="summary-tile"><span>Discount preview</span><strong>INR <span data-po-discount>0.00</span></strong></article><article class="summary-tile"><span>Tax preview</span><strong>INR <span data-po-tax>0.00</span></strong></article><article class="summary-tile"><span>Grand total preview</span><strong>INR <span data-po-total>0.00</span></strong></article></section>
        <div class="form-actions"><a class="button button-secondary" href="{{ $order->exists ? route('restaurant.vendors.purchase-orders.show', $order) : route('restaurant.vendors.purchase-orders.index') }}">Cancel</a><button class="button button-primary" type="submit">Save draft</button></div>
    </form>
    <script src="{{ asset('assets/vendor-purchase-orders.js') }}" defer></script>
@endsection