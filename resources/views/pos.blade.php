@extends('layouts.restaurant')

@section('title', 'Tasty Bite POS')

@section('content')
@php
    $restaurantName = $restaurant->name ?? 'TastyBite';
    $adminAccess = request()->attributes->get('restaurant_admin_access_admin');
    $currentUser = $adminAccess ?? ($currentUser ?? Auth::guard('web')->user());
    $staffName = $currentUser?->name ?? 'Staff';
    $staffRole = $adminAccess ? 'Super Admin' : ucfirst((string) ($currentUser?->role ?? 'owner'));
@endphp

    <main class="main-content">
      <div class="pos-layout" id="posLayout">
        <section class="pos-left" aria-label="Menu and products">
    <header class="top-header">
      <a href="{{ route('pos.index') }}" class="brand">
        @if($restaurant->logo_url)
          <img class="brand-icon brand-logo-image" src="{{ $restaurant->logo_url }}" alt="{{ $restaurantName }} logo">
        @else
          <div class="brand-icon"><i class="bi bi-cup-hot-fill"></i></div>
        @endif
        <div class="brand-text">
          <h2>{{ $restaurantName }}</h2>
          <small>Restaurant Admin <span aria-hidden="true">/</span> POS Billing</small>
        </div>
      </a>

      <div class="header-search">
        <input
          type="search"
          id="menuSearch"
          placeholder="Search for items..."
          aria-label="Search menu"
          autocomplete="off"
        />
        <button class="search-clear" type="button" data-search-clear aria-label="Clear menu search" hidden>
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
        <button class="search-submit" type="button" data-search-focus aria-label="Search menu">
          <i class="bi bi-search" aria-hidden="true"></i>
        </button>
      </div>

      <div class="header-right">
        <div class="header-date" aria-label="Current date and time in Kolkata">
          <i class="bi bi-calendar3"></i>
          <span id="currentDate">-- --- ----</span>
          <span class="header-date-separator" aria-hidden="true">|</span>
          <span id="currentTime">--:--:-- --</span>
        </div>

        <a class="pos-notifications" href="{{ route('restaurant.announcements.index') }}" aria-label="Announcements">
          <i class="bi bi-bell" aria-hidden="true"></i>
        </a>

        @if ($adminAccess)
          <div class="admin-profile" aria-label="Super Admin account">
            <span class="admin-avatar"><i class="bi bi-person-fill"></i></span>
            <span class="admin-meta">
              <span class="admin-name">{{ $staffName }}</span>
              <small>{{ $staffRole }}</small>
            </span>
          </div>
        @else
          <div class="dropdown">
            <button class="admin-profile" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu">
              <span class="admin-avatar"><i class="bi bi-person-fill"></i></span>
              <span class="admin-meta">
                <span class="admin-name">{{ $staffName }}</span>
                <small>{{ $staffRole }}</small>
              </span>
              <i class="bi bi-chevron-down admin-caret" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end admin-menu">
              <li><a class="dropdown-item" href="{{ route('restaurant.profile') }}"><i class="bi bi-person-gear"></i> Profile &amp; Branding</a></li>
              <li><a class="dropdown-item" href="{{ route('restaurant.dashboard') }}"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
              <li><hr class="dropdown-divider"></li>
              <li>
                <form method="POST" action="{{ route('restaurant.logout') }}">
                  @csrf
                  <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right"></i> Log out</button>
                </form>
              </li>
            </ul>
          </div>
        @endif
      </div>
    </header>

          <section class="menu-panel">
            <section class="pos-order-controls" aria-label="Current order details">
              <div class="pos-order-control-grid">
                <div class="pos-control-group">
                  <span class="pos-control-label">Order Type</span>
                  <div class="order-type" role="group" aria-label="Order type">
                    <button type="button" class="active" data-type="Dine In" aria-pressed="true">
                      <i class="bi bi-cup-straw" aria-hidden="true"></i> Dine In
                    </button>
                    <button type="button" data-type="Take Away" aria-pressed="false">
                      <i class="bi bi-bag" aria-hidden="true"></i> Take Away
                    </button>
                    <button type="button" data-type="Delivery" aria-pressed="false">
                      <i class="bi bi-bicycle" aria-hidden="true"></i> Delivery
                    </button>
                  </div>
                </div>
                <div class="pos-table-selection" id="tableField">
                  <button class="pos-table-selection-button" id="openTablePicker" type="button" aria-haspopup="dialog" aria-controls="tablePickerDialog">
                    <span class="pos-table-selection-icon"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i></span>
                    <span class="pos-table-selection-copy">
                      <strong id="posTableSelectionTitle">Select a table</strong>
                      <small id="posTableSelectionDetails">Choose a floor and guest count</small>
                    </span>
                    <i class="bi bi-chevron-right pos-table-selection-arrow" aria-hidden="true"></i>
                  </button>
                </div>
              </div>
            </section>

            <div class="menu-section-heading">
              <div>
                <h2>Menu</h2>
                <span>Browse categories and add items</span>
              </div>
              <a class="pos-history-link" href="{{ route('pos.orders.index') }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i> Order history
              </a>
            </div>
            <div class="category-scroller" id="categoryScroller">
              <button class="cat-arrow cat-prev" type="button" data-cat-scroll="-1" aria-label="Scroll categories left"><i class="bi bi-chevron-left"></i></button>
            <div class="category-list" id="categoryList">
              <button class="category-btn active" type="button" data-category="all" data-category-name="Items">
                <i class="bi bi-grid" aria-hidden="true"></i><span>Items</span>
              </button>

              @foreach($categories as $category)
                <button class="category-btn" type="button" data-category="{{ $category->id }}" data-category-name="{{ $category->name }}">
                  <span class="category-btn-media">
                    <i class="bi bi-{{ \App\Models\RestaurantMenuCategory::iconClassFor($category->icon) }}" aria-hidden="true"></i>
                  </span>
                  <span>{{ $category->name }}</span>
                </button>
              @endforeach
            </div>
              <button class="cat-arrow cat-next" type="button" data-cat-scroll="1" aria-label="Scroll categories right"><i class="bi bi-chevron-right"></i></button>
            </div>

            <div class="food-grid" id="foodGrid">
              @forelse($products as $product)
                @php
                    $foodType = strtolower((string) ($product->food_type ?? 'veg'));
                    $isVeg = $foodType === 'veg' || $foodType === 'vegan';
                    $imageUrl = $product->image_url;
                @endphp
                <article
                  class="food-card"
                  data-product-id="{{ $product->id }}"
                  data-name="{{ $product->name }}"
                  data-category="{{ $product->category_id ?? 0 }}"
                  data-category-name="{{ $product->category?->name ?? 'Uncategorized' }}"
                  data-price="{{ (float) $product->price }}"
                  data-veg="{{ $isVeg ? 'true' : 'false' }}"
                  data-img="{{ $imageUrl }}"
                >
                  <img class="food-image" src="{{ $imageUrl }}" alt="{{ $product->name }}" loading="lazy" />
                  <div class="food-info">
                    <div class="food-name">{{ $product->name }}</div>
                    <span class="food-status">Available</span>
                    <div class="food-bottom">
                      <span class="food-price">₹{{ number_format((float) $product->price, 2) }} @if($isVeg) <small class="veg-dot"></small> @else <small class="nonveg-dot"></small> @endif</span>
                      <button class="add-btn" onclick="addToCart(this)">
                        Add <i class="bi bi-plus-lg"></i>
                      </button>
                    </div>
                  </div>
                </article>
              @empty
                <div class="empty-cart" style="grid-column: 1 / -1;">
                  <i class="bi bi-basket2"></i>
                  No menu items available yet. Add products from the restaurant owner panel to populate the POS.
                </div>
              @endforelse
              @if($products->isNotEmpty())
                <div class="empty-menu-state" id="noMatchingProducts" role="status" aria-live="polite" hidden>
                  <i class="bi bi-search" aria-hidden="true"></i>
                  <strong>No matching products</strong>
                  <span>Try another search or category.</span>
                </div>
              @endif
            </div>
          </section>
        </section>

          <aside class="billing-panel" id="billingPanel" aria-label="Current order and billing">
            <section class="checkout-dialog" aria-labelledby="checkoutTitle">
              <header class="checkout-header">
                <div>
                  <h2 id="checkoutTitle"><i class="bi bi-receipt" aria-hidden="true"></i>Orders</h2>
                  <p>Review items and complete billing</p>
                </div>
                <div class="checkout-header-actions">
                  <button
                    class="held-bills-trigger"
                    id="openHeldBills"
                    type="button"
                    aria-haspopup="dialog"
                    aria-controls="heldBillsDialog"
                    data-held-bills-url="{{ route('pos.held-bills.index') }}"
                    data-held-bills-store-url="{{ route('pos.held-bills.store') }}"
                    data-held-bills-base-url="{{ url('/pos/held-bills') }}"
                  >
                    <i class="bi bi-receipt-cutoff" aria-hidden="true"></i>
                    <span>Hold Bills</span>
                    <span class="held-bills-badge" id="heldBillsCount">{{ $heldBillsCount }}</span>
                  </button>
                  <button class="clear-order-button" type="button" onclick="clearCart()">
                    <i class="bi bi-trash3" aria-hidden="true"></i> Clear
                  </button>
                </div>
              </header>

              <div class="checkout-body">
                <section class="checkout-summary" aria-labelledby="summaryTitle">
                  <h3 id="summaryTitle">Order Summary <span class="cart-item-count" id="nextBillingCount">0 items</span></h3>
                  <div class="cart-heading">
                    <span>Item</span><span>Qty</span><span>Price</span><span>Total</span><span></span>
                  </div>
                  <div class="cart-items" id="cartItems" aria-live="polite" role="region" aria-label="Cart items" tabindex="0">
                    <div class="empty-cart">
                      <i class="bi bi-cart3" aria-hidden="true"></i>
                      Add food items to start billing
                    </div>
                  </div>

                  <div class="bill-summary">
                    <div class="summary-row">
                      <span>Subtotal</span>
                      <strong id="subtotal">₹0.00</strong>
                    </div>
                    <div class="summary-row">
                      <div class="discount-label">
                        <label for="discount">Discount</label>
                        <div class="discount-input">
                          <input type="number" id="discount" min="0" max="100" value="0" aria-label="Discount percentage" />
                          <span>%</span>
                        </div>
                      </div>
                      <strong id="discountAmount">₹0.00</strong>
                    </div>
                    <div class="summary-row">
                      <span>CGST (2.5%)</span>
                      <strong id="cgst">₹0.00</strong>
                    </div>
                    <div class="summary-row">
                      <span>SGST (2.5%)</span>
                      <strong id="sgst">₹0.00</strong>
                    </div>
                    <div class="grand-total">
                      <span>Grand Total</span>
                      <span id="grandTotal">₹0.00</span>
                    </div>
                  </div>
                </section>

                <section class="pos-billing-options" aria-label="Payment method">
                  <h3>Payment Method</h3>
                  <div class="payment-methods" role="group" aria-label="Payment method">
                    <button type="button" class="payment-method active" data-payment-method="Cash" aria-pressed="true">
                      <i class="bi bi-cash-coin" aria-hidden="true"></i><span>Cash</span>
                    </button>
                    <button type="button" class="payment-method" data-payment-method="Card" aria-pressed="false">
                      <i class="bi bi-credit-card" aria-hidden="true"></i><span>Card</span>
                    </button>
                    <button type="button" class="payment-method" data-payment-method="UPI" aria-pressed="false">
                      <i class="bi bi-qr-code" aria-hidden="true"></i><span>UPI</span>
                    </button>
                  </div>
                </section>

              </div>
              <footer class="checkout-footer">
                <div class="checkout-footer-actions">
                  <button class="action-btn hold-btn" type="button" id="holdOrderButton" aria-label="Hold current bill or restore the most recent held bill when empty" title="Hold current bill or restore the most recent held bill when empty">
                    <i class="bi bi-pause-fill" aria-hidden="true"></i> Hold Bill
                  </button>
                  <button class="action-btn print-btn" type="button" id="printBillButton">
                    <i class="bi bi-printer" aria-hidden="true"></i> Print Bill
                  </button>
                  <button class="action-btn complete-sale-btn" id="completeSale" type="button">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Complete Sale
                  </button>
                </div>
              </footer>
            </section>
          </aside>

      </div>
      @if(session('status'))
        <div class="pos-status-message" role="status">{{ session('status') }}</div>
      @endif
    </main>

    <dialog class="held-bills-dialog" id="heldBillsDialog" aria-labelledby="heldBillsTitle" aria-describedby="heldBillsDescription">
      <section class="held-bills-sheet">
        <header class="held-bills-header">
          <div class="held-bills-heading">
            <span class="held-bills-heading-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
            <div>
              <div class="held-bills-title-row">
                <h2 id="heldBillsTitle">Hold Bills</h2>
                <span class="held-bills-modal-count" id="heldBillsModalCount">{{ $heldBillsCount }}</span>
              </div>
              <p id="heldBillsDescription">Select a held bill to resume or delete</p>
            </div>
          </div>
          <button class="held-bills-close" type="button" data-close-held-bills aria-label="Close held bills">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </header>
        <div class="held-bills-status" id="heldBillsStatus" role="alert" hidden></div>
        <div class="held-bills-list" id="heldBillsList" role="list" aria-label="Held bills" aria-live="polite">
          @if($heldBillsCount === 0)
            <div class="held-bills-empty">
              <span class="held-bills-empty-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
              <strong>No held bills</strong>
              <span>Orders you put on hold will appear here.</span>
            </div>
          @else
            <div class="held-bills-loading" role="status">Loading held bills...</div>
          @endif
        </div>
      </section>
    </dialog>

    <dialog class="pos-table-dialog" id="tablePickerDialog" aria-labelledby="tablePickerTitle" aria-describedby="tablePickerDescription" data-table-required="{{ $hasActiveTables ? 'true' : 'false' }}">
      <div class="pos-table-dialog-shell">
        <header class="pos-table-dialog-header">
          <div>
            <span class="pos-table-dialog-eyebrow">DINE IN</span>
            <h2 id="tablePickerTitle">Select Table &amp; Guests</h2>
            <p id="tablePickerDescription">Choose a floor, then select a table for this order.</p>
          </div>
          <button class="pos-table-dialog-close" type="button" data-close-table-picker aria-label="Close table selection">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </header>

        <div class="pos-table-dialog-content">
          <div class="pos-table-picker-controls">
            <div id="floorField">
              <label for="posFloor">Floor</label>
              <select id="posFloor">
                <option value="">Choose floor</option>
                @foreach($tableFloors as $floor)<option value="{{ $floor->id }}">{{ $floor->name }}</option>@endforeach
              </select>
            </div>
            <div id="sectionField">
              <label for="posSection">Section</label>
              <select id="posSection">
                <option value="">All sections</option>
                @foreach($tableFloors as $floor)@foreach($floor->sections as $section)<option value="{{ $section->id }}" data-floor="{{ $floor->id }}">{{ $section->name }}</option>@endforeach @endforeach
              </select>
            </div>
          </div>

          <div class="pos-table-grid-heading">
            <div>
              <h3>Tables</h3>
              <p id="posTableAvailability" aria-live="polite">Choose a floor to view tables.</p>
            </div>
            <div class="pos-table-legend" aria-label="Table availability legend">
              <span class="is-available"><i></i> Available</span>
              <span class="is-occupied"><i></i> Occupied</span>
              <span class="is-reserved"><i></i> Reserved</span>
            </div>
          </div>

          <div class="pos-table-card-grid" id="posTableCardGrid">
            @foreach($posTables as $table)
              @php
                $tableReserved = $table->reservations->isNotEmpty();
                $tableStatus = $tableReserved ? 'reserved' : ($table->status === 'occupied' ? 'occupied' : 'available');
              @endphp
              <button class="pos-table-card" type="button" data-pos-table-card data-table-id="{{ $table->id }}" data-floor="{{ $table->floor_id }}" data-section="{{ $table->section_id }}" data-capacity="{{ $table->capacity }}" data-status="{{ $tableStatus }}" aria-pressed="false" @disabled($tableReserved)>
                <span class="pos-table-card-top">
                  <strong>Table {{ $table->table_number }}</strong>
                  <span class="pos-table-status" data-table-status-label>{{ ucfirst($tableStatus) }}</span>
                </span>
                <span class="pos-table-card-capacity"><i class="bi bi-people" aria-hidden="true"></i> {{ $table->capacity }} seats</span>
                @if($table->activeOccupancy?->group_id)<span class="pos-table-card-note">Combined party</span>@elseif($table->status === 'occupied')<span class="pos-table-card-note">Seated party</span>@endif
                <i class="bi bi-check-circle-fill pos-table-card-check" aria-hidden="true"></i>
              </button>
            @endforeach
            <div class="pos-table-empty-state" id="posTableEmptyState" role="status" hidden>
              <span class="pos-table-empty-icon"><i class="bi bi-layout-three-columns" aria-hidden="true"></i></span>
              <strong id="posTableEmptyTitle">No tables available</strong>
              <span id="posTableEmptyMessage">Choose another floor or section, or review table availability.</span>
            </div>
          </div>

          <div class="pos-table-dialog-footer">
            <div class="pos-table-guest-control">
              <label for="guestNo">Guests</label>
              <select id="guestNo">
                @for($guest = 1; $guest <= 20; $guest++)<option value="{{ $guest }}" @selected($guest === 2)>{{ $guest }}</option>@endfor
              </select>
            </div>
            <button class="pos-table-confirm-button" id="confirmTableSelection" type="button" disabled>
              Confirm Table Selection <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <div class="pos-table-select-source" aria-hidden="true">
          <select id="tableNo" tabindex="-1">
            <option value="">Choose a floor first</option>
            @foreach($posTables as $table)
              @php($tableReserved = $table->reservations->isNotEmpty())
              <option value="{{ $table->id }}" data-floor="{{ $table->floor_id }}" data-section="{{ $table->section_id }}" data-capacity="{{ $table->capacity }}" data-status="{{ $tableReserved ? 'reserved' : ($table->status === 'occupied' ? 'occupied' : 'available') }}" @if($table->activeOccupancy?->group_id) data-group="{{ $table->activeOccupancy->group_id }}" @endif @disabled($tableReserved)>{{ $table->table_number }} · {{ $table->capacity }} seats · {{ $table->floor?->name }}{{ $table->section ? ' · '.$table->section->name : '' }}{{ $table->activeOccupancy?->group_id ? ' · combined party' : ($table->status === 'occupied' ? ' · seated party' : '') }}</option>
            @endforeach
          </select>
        </div>
      </div>
    </dialog>

    <form id="completeSaleForm" method="POST" action="{{ route('pos.orders.store') }}" hidden>
      @csrf
      <input type="hidden" name="idempotency_key" value="{{ $posIdempotencyKey }}">
      <input type="hidden" name="items_json" id="posItemsJson">
      <input type="hidden" name="discount_percent" id="posDiscountPercent">
      <input type="hidden" name="order_type" id="posOrderType">
      <input type="hidden" name="table_number" id="posTableNumber">
      <input type="hidden" name="table_id" id="posTableId">
      <input type="hidden" name="floor_id" id="posFloorId">
      <input type="hidden" name="section_id" id="posSectionId">
      <input type="hidden" name="guest_count" id="posGuestCount">
      <input type="hidden" name="payment_method" id="posPaymentMethod">
      <input type="hidden" name="resumed_order_id" id="posResumedOrderId">
    </form>

@endsection
