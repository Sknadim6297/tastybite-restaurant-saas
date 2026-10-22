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
          <small>Restaurant POS</small>
        </div>
      </a>

      <nav class="header-nav" aria-label="POS navigation">
        <a href="{{ route('pos.index') }}" class="active" aria-current="page">Billing</a>
        <a href="{{ route('pos.orders.index') }}">Order history</a>
        <a href="{{ route('restaurant.dashboard') }}">Dashboard</a>
      </nav>

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

    <div class="app-layout">
      <aside class="sidebar">
        <a href="#" class="side-link active">
          <i class="bi bi-cart-fill"></i> Billing (POS)
        </a>

        <a href="#" class="side-link">
          <i class="bi bi-layout-three-columns"></i> Table Management
        </a>

        <a href="#" class="side-link"> <i class="bi bi-receipt"></i> Orders </a>

        <a href="#" class="side-link">
          <i class="bi bi-journal-text"></i> Menu Items
        </a>

        <a href="#" class="side-link">
          <i class="bi bi-box-seam"></i> Inventory
        </a>

        <a href="#" class="side-link">
          <i class="bi bi-people"></i> Customers
        </a>

        <a href="#" class="side-link">
          <i class="bi bi-bar-chart-line"></i> Reports
        </a>

        <a href="#" class="side-link">
          <i class="bi bi-cash-coin"></i> Expenses
        </a>

        <a href="#" class="side-link"> <i class="bi bi-gear"></i> Settings </a>

        <div class="sidebar-bottom">
          <strong>{{ $restaurantName }} POS</strong>
          Restaurant Billing System
        </div>
      </aside>

      <main class="main-content">
        <div class="pos-layout" id="posLayout">
          <section class="menu-panel">
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

          <aside class="billing-panel" id="billingPanel" aria-label="Restaurant checkout" aria-hidden="true" inert>
            <section class="checkout-dialog" role="dialog" aria-modal="true" aria-labelledby="checkoutTitle">
              <header class="checkout-header">
                <div>
                  <h2 id="checkoutTitle">Restaurant Checkout</h2>
                  <p>Review your order and complete billing</p>
                </div>
                <button class="checkout-close" id="billingClose" type="button" aria-label="Close checkout">
                  <i class="bi bi-x-lg" aria-hidden="true"></i>
                </button>
              </header>

              <div class="checkout-body">
                <section class="checkout-details" aria-label="Order details">
                  <div class="checkout-section">
                    <h3>Order Type</h3>
                    <div class="order-type">
                      <button type="button" class="active" data-type="Dine In">
                        <i class="bi bi-cup-straw" aria-hidden="true"></i> Dine In
                      </button>
                      <button type="button" data-type="Take Away">
                        <i class="bi bi-bag" aria-hidden="true"></i> Take Away
                      </button>
                      <button type="button" data-type="Delivery">
                        <i class="bi bi-bicycle" aria-hidden="true"></i> Delivery
                      </button>
                    </div>
                  </div>

                  <div class="checkout-section">
                    <h3>Table Details</h3>
                    <div class="table-fields">
                      <div id="tableField">
                        <label for="tableNo">Table No.</label>
                        <select id="tableNo">
                          <option>1</option><option>2</option><option>3</option>
                          <option>4</option><option selected>5</option>
                          <option>6</option><option>7</option><option>8</option>
                        </select>
                      </div>
                      <div>
                        <label for="guestNo">Guest Count</label>
                        <select id="guestNo">
                          <option>1</option><option>2</option><option>3</option>
                          <option selected>4</option><option>5</option>
                          <option>6</option><option>7</option><option>8</option>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div class="checkout-section">
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
                  </div>

                  <div class="checkout-section">
                    <h3>Payment Details</h3>
                    <p class="payment-notice" id="paymentNotice" aria-live="polite">
                      <i class="bi bi-info-circle" aria-hidden="true"></i>
                      <span>Payment is not processed here. Completing a sale records the order and consumes configured recipe stock.</span>
                    </p>
                  </div>
                  <div class="checkout-section">
                    <p class="payment-notice">Menu products without an active recipe can be sold, but do not deduct inventory.</p>
                  </div>

                  <div class="checkout-section">
                    <h3>Order Notes <span>(Optional)</span></h3>
                    <textarea
                      id="orderNotes"
                      class="order-notes"
                      placeholder="Add a note for the kitchen or cashier..."
                      rows="2"
                    ></textarea>
                  </div>
                </section>

                <section class="checkout-summary" aria-labelledby="summaryTitle">
                  <h3 id="summaryTitle">Order Summary</h3>
                  <div class="cart-heading">
                    <span>Item</span><span>Qty</span><span>Price</span><span>Total</span><span></span>
                  </div>
                  <div class="cart-items" id="cartItems">
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
              </div>

              <footer class="checkout-footer">
                <button class="billing-back-button" id="billingBack" type="button">
                  <i class="bi bi-arrow-left" aria-hidden="true"></i><span>Back to Products</span>
                </button>
                <div class="checkout-footer-actions">
                  <button class="action-btn hold-btn" type="button" onclick="holdOrder()">
                    <i class="bi bi-pause-fill" aria-hidden="true"></i> Hold
                  </button>
                  <button class="action-btn clear-btn" type="button" onclick="clearCart()">
                    <i class="bi bi-trash3" aria-hidden="true"></i> Clear
                  </button>
                  <button class="action-btn complete-sale-btn" id="completeSale" type="button">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Complete Sale
                  </button>
                </div>
              </footer>
            </section>
          </aside>
        </div>
      </main>
    </div>

    @if(session('status'))
      <div class="pos-status-message" role="status">{{ session('status') }}</div>
    @endif

    <form id="completeSaleForm" method="POST" action="{{ route('pos.orders.store') }}" hidden>
      @csrf
      <input type="hidden" name="idempotency_key" value="{{ $posIdempotencyKey }}">
      <input type="hidden" name="items_json" id="posItemsJson">
      <input type="hidden" name="discount_percent" id="posDiscountPercent">
      <input type="hidden" name="notes" id="posOrderNotes">
    </form>

    <button class="next-billing-button" id="nextBilling" type="button" aria-controls="billingPanel" aria-expanded="false">
      <span>Next: Billing</span>
      <span class="next-billing-count" id="nextBillingCount" aria-label="0 items">0</span>
      <i class="bi bi-arrow-right" aria-hidden="true"></i>
    </button>

    <div
      class="modal fade"
      id="holdOrdersModal"
      tabindex="-1"
      aria-labelledby="holdOrdersTitle"
      aria-hidden="true"
    >
      <div
        class="modal-dialog modal-dialog-centered modal-dialog-scrollable"
        style="max-width: 450px"
      >
        <div class="modal-content rounded-4 shadow border-0">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title fw-bold" id="holdOrdersTitle">
              <i class="bi bi-pause-circle me-2"></i>
              Held Orders
            </h5>

            <button
              type="button"
              class="btn-close btn-close-white"
              data-bs-dismiss="modal"
              aria-label="Close"
            ></button>
          </div>

          <div class="modal-body">
            <div id="heldOrdersList">
              <p class="text-center text-muted py-3">No held orders.</p>
            </div>
          </div>
        </div>
      </div>
    </div>

@endsection
