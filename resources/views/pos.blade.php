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

    <main class="main-content">
        <div class="pos-layout" id="posLayout">
          <section class="menu-panel">
            <section class="pos-order-controls" aria-label="Current order details">
              <div class="pos-control-heading">
                <div>
                  <h2>New Order</h2>
                  <span>Choose order type and table details</span>
                </div>
                <span class="pos-live-indicator"><i aria-hidden="true"></i> Live order</span>
              </div>
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
                <div class="pos-order-selects">
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
                  <div id="tableField">
                    <label for="tableNo">Table</label>
                    <select id="tableNo">
                      <option value="">Choose a floor first</option>
                      @foreach($posTables as $table)
                        <option value="{{ $table->id }}" data-floor="{{ $table->floor_id }}" data-section="{{ $table->section_id }}" data-capacity="{{ $table->capacity }}" @if($table->activeOccupancy?->group_id) data-group="{{ $table->activeOccupancy->group_id }}" @endif>{{ $table->table_number }} · {{ $table->capacity }} seats · {{ $table->floor?->name }}{{ $table->section ? ' · '.$table->section->name : '' }}{{ $table->activeOccupancy?->group_id ? ' · combined party' : ($table->status === 'occupied' ? ' · seated party' : '') }}</option>
                      @endforeach
                    </select>
                    @if($posTables->isEmpty())<small class="pos-table-empty">No available tables. Configure floors or resolve table availability.</small>@endif
                  </div>
                  <div>
                    <label for="guestNo">Guests</label>
                    <select id="guestNo">
                      @for($guest = 1; $guest <= 20; $guest++)<option value="{{ $guest }}" @selected($guest === 2)>{{ $guest }}</option>@endfor
                    </select>
                  </div>
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
              <button class="category-btn active" type="button" data-category="all" data-category-name="All">
                <i class="bi bi-grid"></i> All
              </button>

              @foreach($categories as $category)
                <button class="category-btn" type="button" data-category="{{ $category->id }}" data-category-name="{{ $category->name }}">
                  <span class="category-btn-media">
                    @if($category->image_path)
                      <img class="category-btn-image" src="{{ $category->image_url }}" alt="" loading="lazy">
                    @endif
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

          <aside class="billing-panel" id="billingPanel" aria-label="Current order and billing">
            <section class="checkout-dialog" aria-labelledby="checkoutTitle">
              <header class="checkout-header">
                <div>
                  <h2 id="checkoutTitle"><i class="bi bi-receipt" aria-hidden="true"></i> Current Order</h2>
                  <p>Review items and complete billing</p>
                </div>
                <button class="clear-order-button" type="button" onclick="clearCart()">
                  <i class="bi bi-trash3" aria-hidden="true"></i> Clear
                </button>
              </header>

              <div class="checkout-body">
                <section class="checkout-summary" aria-labelledby="summaryTitle">
                  <h3 id="summaryTitle">Order Summary <span class="cart-item-count" id="nextBillingCount">0 items</span></h3>
                  <div class="cart-heading">
                    <span>Item</span><span>Qty</span><span>Price</span><span>Total</span><span></span>
                  </div>
                  <div class="cart-items" id="cartItems" aria-live="polite">
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

                <section class="pos-billing-options" aria-label="Payment and order notes">
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
                  <p class="payment-notice" id="paymentNotice" aria-live="polite">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span>Choose a method, then confirm receipt only after the full payment has actually been received.</span>
                  </p>
                  <label class="payment-confirmation" for="paymentReceivedConfirmed">
                    <input id="paymentReceivedConfirmed" type="checkbox">
                    <span>I confirm the full payment was received through the selected method.</span>
                  </label>
                  <label for="orderNotes">Order Notes <span>(Optional)</span></label>
                  <textarea id="orderNotes" class="order-notes" placeholder="Add a note for the kitchen or cashier..." rows="2"></textarea>
                </section>

                <p class="recipe-stock-notice">
                  <i class="bi bi-info-circle" aria-hidden="true"></i>
                  Completing records the sale and consumes configured recipe stock. Items without an active recipe do not deduct inventory.
                </p>

                <footer class="checkout-footer">
                  <div class="checkout-footer-actions">
                    <button class="action-btn hold-btn" type="button" id="holdOrderButton">
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

                <section class="held-orders-section" aria-labelledby="heldOrdersTitle">
                  <div class="held-orders-heading">
                    <h3 id="heldOrdersTitle">Held Bills</h3>
                    <span id="heldOrderCount">0</span>
                  </div>
                  <div id="heldOrdersList" class="held-orders-list">
                    <p class="held-orders-empty">No held bills.</p>
                  </div>
                </section>
              </div>
            </section>
          </aside>
        </div>
    </main>

    @if(session('status'))
      <div class="pos-status-message" role="status">{{ session('status') }}</div>
    @endif

    <form id="completeSaleForm" method="POST" action="{{ route('pos.orders.store') }}" hidden>
      @csrf
      <input type="hidden" name="idempotency_key" value="{{ $posIdempotencyKey }}">
      <input type="hidden" name="items_json" id="posItemsJson">
      <input type="hidden" name="discount_percent" id="posDiscountPercent">
      <input type="hidden" name="notes" id="posOrderNotes">
      <input type="hidden" name="order_type" id="posOrderType">
      <input type="hidden" name="table_number" id="posTableNumber">
      <input type="hidden" name="table_id" id="posTableId">
      <input type="hidden" name="floor_id" id="posFloorId">
      <input type="hidden" name="section_id" id="posSectionId">
      <input type="hidden" name="guest_count" id="posGuestCount">
      <input type="hidden" name="payment_method" id="posPaymentMethod">
      <input type="hidden" name="payment_confirmed" id="posPaymentConfirmed" value="0">
    </form>

@endsection
