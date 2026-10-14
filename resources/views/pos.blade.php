@extends('layouts.restaurant')

@section('title', 'Tasty Bite POS')

@section('content')
@php
    $restaurantName = $restaurant->name ?? 'TastyBite';
    $currentUser = $currentUser ?? Auth::guard('web')->user();
    $staffName = $currentUser?->name ?? 'Staff';
    $staffRole = ucfirst((string) ($currentUser?->role ?? 'owner'));
    $timezone = config('app.timezone', 'UTC');
@endphp

    <header class="top-header">
      <a href="#" class="brand">
        <div class="brand-icon"><i class="bi bi-cup-hot-fill"></i></div>
        <div>
          <h2>{{ $restaurantName }}</h2>
          <small>RESTAURANT</small>
        </div>
      </a>

      <div class="header-search">
        <i class="bi bi-search"></i>
        <input
          type="search"
          id="menuSearch"
          placeholder="Search for items (e.g. Biryani, Paneer, Juice...)"
          aria-label="Search menu"
        />
      </div>

      <div class="header-right">
        <div class="header-date">
          <i class="bi bi-calendar3"></i>
          <span id="currentDate" data-timezone="{{ $timezone }}">Today</span><br />
          <span id="currentTime">00:00</span>
        </div>

        <button
          class="header-icon"
          aria-label="Notifications"
          onclick="alert('No new notifications.')"
        >
          <i class="bi bi-bell"></i>
          <span class="notification-dot">3</span>
        </button>

        <div class="admin-profile">
          <div class="admin-avatar"><i class="bi bi-person-fill"></i></div>
          <span>{{ $staffName }}</span>
          <small>{{ $staffRole }}</small>
          <i class="bi bi-chevron-down"></i>
        </div>
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
        <div class="pos-layout">
          <section class="menu-panel">
            <div class="category-list" id="categoryList">
              <button class="category-btn active" type="button" data-category="all" data-category-name="All">
                <i class="bi bi-grid"></i> All
              </button>

              @foreach($categories as $category)
                <button class="category-btn" type="button" data-category="{{ $category->id }}" data-category-name="{{ $category->name }}">
                  @if($category->image_path)
                    <img class="category-btn-image" src="{{ $category->image_url }}" alt="" loading="lazy">
                  @else
                    <i class="bi bi-{{ $category->icon ?: 'grid' }}"></i>
                  @endif
                  <span>{{ $category->name }}</span>
                </button>
              @endforeach
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
            </div>
          </section>

          <aside class="billing-panel">
            <div class="order-type">
              <button type="button" class="active" data-type="Dine In">
                <i class="bi bi-cup-straw"></i> Dine In
              </button>
              <button type="button" data-type="Take Away">
                <i class="bi bi-bag"></i> Take Away
              </button>
              <button type="button" data-type="Delivery">
                <i class="bi bi-bicycle"></i> Delivery
              </button>
            </div>

            <div class="table-fields">
              <div id="tableField">
                <label for="tableNo">Table No.</label>
                <select id="tableNo">
                  <option>1</option>
                  <option>2</option>
                  <option>3</option>
                  <option>4</option>
                  <option selected>5</option>
                  <option>6</option>
                  <option>7</option>
                  <option>8</option>
                </select>
              </div>

              <div>
                <label for="guestNo">Guest</label>
                <select id="guestNo">
                  <option>1</option>
                  <option>2</option>
                  <option>3</option>
                  <option selected>4</option>
                  <option>5</option>
                  <option>6</option>
                  <option>7</option>
                  <option>8</option>
                </select>
              </div>
            </div>

            <div class="cart-heading">
              <span>Item</span>
              <span class="text-center">Qty</span>
              <span class="text-center">Price</span>
              <span class="text-center">Total</span>
              <span></span>
            </div>

            <div class="cart-items" id="cartItems">
              <div class="empty-cart">
                <i class="bi bi-cart3"></i>
                Add food items to start billing
              </div>
            </div>

            <textarea
              id="orderNotes"
              class="order-notes"
              placeholder="Order Notes (e.g. less spicy, extra chutney...)"
              rows="1"
            ></textarea>

            <div class="bill-summary">
              <div class="summary-row">
                <span>Subtotal</span>
                <strong id="subtotal">₹0.00</strong>
              </div>

              <div class="summary-row">
                <div class="d-flex align-items-center gap-2">
                  <span>Discount</span>
                  <div class="discount-input">
                    <span>%</span>
                    <input
                      type="number"
                      id="discount"
                      min="0"
                      max="100"
                      value="0"
                      aria-label="Discount percentage"
                    />
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

              <div class="bill-actions">
                <button class="action-btn clear-btn" onclick="clearCart()">
                  <i class="bi bi-trash3"></i> Clear
                </button>

                <button
                  type="button"
                  class="action-btn hold-btn"
                  onclick="holdOrder()"
                >
                  <i class="bi bi-pause-fill"></i> Hold
                </button>
              </div>

              <button class="generate-btn" onclick="generateBill()">
                <i class="bi bi-credit-card-2-front me-2"></i>
                Generate Bill
              </button>
            </div>
          </aside>
        </div>
      </main>
    </div>

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
              <p class="text-center text-white py-3">No held orders.</p>
            </div>
          </div>
        </div>
      </div>
    </div>

@endsection
