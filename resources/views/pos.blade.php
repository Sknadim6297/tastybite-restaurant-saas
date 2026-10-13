@extends('layouts.restaurant')

@section('title', 'Tasty Bite POS')

@section('content')
<!-- HEADER -->
    <header class="top-header">
      <a href="#" class="brand">
        <div class="brand-icon"><i class="bi bi-cup-hot-fill"></i></div>
        <div>
          <h2>Tasty<span>Bite</span></h2>
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
          <span id="currentDate">Today</span><br />
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
          <span>Admin</span>
          <i class="bi bi-chevron-down"></i>
        </div>
      </div>
    </header>

    <div class="app-layout">
      <!-- SIDEBAR: DIRECT LINKS, NO SUBMENU -->
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
          <strong>TastyBite POS</strong>
          Restaurant Billing System
        </div>
      </aside>

      <!-- MAIN CONTENT -->
      <main class="main-content">
        <div class="pos-layout">
          <!-- FOOD MENU -->
          <section class="menu-panel">
            <div class="category-list" id="categoryList">
              <button class="category-btn active" data-category="All">
                <i class="bi bi-grid"></i> All
              </button>

              <button class="category-btn" data-category="Starters">
                <i class="bi bi-egg-fried"></i> Starters
              </button>

              <button class="category-btn" data-category="Main Course">
                <i class="bi bi-cup-hot"></i> Main Course
              </button>

              <button class="category-btn" data-category="Biryani">
                <i class="bi bi-emoji-smile"></i> Biryani
              </button>

              <button class="category-btn" data-category="Chinese">
                <i class="bi bi-fire"></i> Chinese
              </button>

              <button class="category-btn" data-category="Tandoor">
                <i class="bi bi-brightness-high"></i> Tandoor
              </button>

              <button class="category-btn" data-category="Desserts">
                <i class="bi bi-cake2"></i> Desserts
              </button>

              <button class="category-btn" data-category="Beverages">
                <i class="bi bi-cup-straw"></i> Beverages
              </button>
            </div>

            <div class="food-grid" id="foodGrid">
              <!-- Image URLs are demo images; replace with actual product photos. -->

              <article
                class="food-card"
                data-name="Chicken Biryani"
                data-category="Biryani"
                data-price="200"
                data-veg="false"
                data-img="https://images.pexels.com/photos/16020573/pexels-photo-16020573.jpeg"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1563379091339-03246963d51a?auto=format&fit=crop&w=500&q=80"
                  alt="Chicken Biryani"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Chicken Biryani</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹200 <small class="nonveg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Mutton Biryani"
                data-category="Biryani"
                data-price="280"
                data-veg="false"
                data-img="https://images.unsplash.com/photo-1589302168068-964664d93dc0?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1589302168068-964664d93dc0?auto=format&fit=crop&w=500&q=80"
                  alt="Mutton Biryani"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Mutton Biryani</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹280 <small class="nonveg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Paneer Butter Masala"
                data-category="Main Course"
                data-price="220"
                data-veg="true"
                data-img="https://images.unsplash.com/photo-1631452180519-c014fe946bc7?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1631452180519-c014fe946bc7?auto=format&fit=crop&w=500&q=80"
                  alt="Paneer Butter Masala"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Paneer Butter Masala</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹220 <small class="veg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Chicken Butter Masala"
                data-category="Main Course"
                data-price="240"
                data-veg="false"
                data-img="https://images.unsplash.com/photo-1603894584373-5ac82b2ae398?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1603894584373-5ac82b2ae398?auto=format&fit=crop&w=500&q=80"
                  alt="Chicken Butter Masala"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Chicken Butter Masala</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹240 <small class="nonveg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Veg Fried Rice"
                data-category="Chinese"
                data-price="150"
                data-veg="true"
                data-img="https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1603133872878-684f208fb84b?auto=format&fit=crop&w=500&q=80"
                  alt="Veg Fried Rice"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Veg Fried Rice</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹150 <small class="veg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Chicken Fried Rice"
                data-category="Chinese"
                data-price="180"
                data-veg="false"
                data-img="https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1512058564366-18510be2db19?auto=format&fit=crop&w=500&q=80"
                  alt="Chicken Fried Rice"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Chicken Fried Rice</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹180 <small class="nonveg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Chilli Chicken"
                data-category="Starters"
                data-price="220"
                data-veg="false"
                data-img="https://images.unsplash.com/photo-1603894584373-5ac82b2ae398?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1603894584373-5ac82b2ae398?auto=format&fit=crop&w=500&q=80"
                  alt="Chilli Chicken"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Chilli Chicken</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹220 <small class="nonveg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Paneer Tikka"
                data-category="Starters"
                data-price="200"
                data-veg="true"
                data-img="https://images.unsplash.com/photo-1567188040759-fb8a883dc6d8?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1567188040759-fb8a883dc6d8?auto=format&fit=crop&w=500&q=80"
                  alt="Paneer Tikka"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Paneer Tikka</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹200 <small class="veg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Garlic Naan"
                data-category="Tandoor"
                data-price="60"
                data-veg="true"
                data-img="https://images.unsplash.com/photo-1610057099431-d73a1c9d2f2f?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1610057099431-d73a1c9d2f2f?auto=format&fit=crop&w=500&q=80"
                  alt="Garlic Naan"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Garlic Naan</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹60 <small class="veg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Butter Naan"
                data-category="Tandoor"
                data-price="50"
                data-veg="true"
                data-img="https://images.unsplash.com/photo-1601050690597-df0568f70950?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1601050690597-df0568f70950?auto=format&fit=crop&w=500&q=80"
                  alt="Butter Naan"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Butter Naan</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹50 <small class="veg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>

              <article
                class="food-card"
                data-name="Gulab Jamun"
                data-category="Desserts"
                data-price="80"
                data-veg="true"
                data-img="https://images.unsplash.com/photo-1666190094766-2d0f7e2b43b9?auto=format&fit=crop&w=500&q=80"
              >
                <img
                  class="food-image"
                  src="https://images.unsplash.com/photo-1666190094766-2d0f7e2b43b9?auto=format&fit=crop&w=500&q=80"
                  alt="Gulab Jamun"
                  loading="lazy"
                />
                <div class="food-info">
                  <div class="food-name">Gulab Jamun</div>
                  <div class="food-bottom">
                    <span class="food-price"
                      >₹80 <small class="veg-dot"></small
                    ></span>
                    <button class="add-btn" onclick="addToCart(this)">
                      Add <i class="bi bi-plus-lg"></i>
                    </button>
                  </div>
                </div>
              </article>
            </div>
          </section>

          <!-- BILLING -->
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

    <!-- HELD ORDERS MODAL -->
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
