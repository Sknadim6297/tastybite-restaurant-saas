
// ===================== TASTYBITE POS SCRIPT =====================

const cart = new Map();
let activeCategory = "All";
let orderType = "Dine In";
let heldOrders = [];
let holdOrderCounter = 1;

// Currency formatting
const money = value => "₹" + (Number(value) || 0).toLocaleString("en-IN", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
});

// Escape HTML
function holdEscape(value) {
    return String(value ?? "").replace(/[&<>"']/g, ch => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    })[ch]);
}

// Clock
function updateClock() {
    const dateEl = document.getElementById("currentDate");
    const timeEl = document.getElementById("currentTime");
    const now = new Date();

    if (dateEl) {
        dateEl.textContent = now.toLocaleDateString("en-IN", {
            weekday: "short",
            day: "2-digit",
            month: "short",
            year: "numeric"
        });
    }

    if (timeEl) {
        timeEl.textContent = now.toLocaleTimeString("en-IN", {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit"
        });
    }
}

updateClock();
setInterval(updateClock, 1000);

// Category filtering
document.querySelectorAll(".category-btn").forEach(button => {
    button.addEventListener("click", () => {
        document.querySelectorAll(".category-btn")
            .forEach(btn => btn.classList.remove("active"));

        button.classList.add("active");
        activeCategory = button.dataset.category || "All";
        filterMenu();
    });
});

// Menu search
const menuSearch = document.getElementById("menuSearch");

if (menuSearch) {
    menuSearch.addEventListener("input", filterMenu);
}

function filterMenu() {
    const search = (
        document.getElementById("menuSearch")?.value || ""
    ).toLowerCase();

    document.querySelectorAll(".food-card").forEach(card => {
        const matchesCategory =
            activeCategory === "All" ||
            card.dataset.category === activeCategory;

        const matchesSearch =
            (card.dataset.name || "").toLowerCase().includes(search);

        card.style.display =
            matchesCategory && matchesSearch ? "" : "none";
    });
}

// Add food to cart
function addToCart(button) {
    const card = button?.closest(".food-card");
    if (!card) return;

    const name = card.dataset.name;
    if (!name) return;

    if (cart.has(name)) {
        cart.get(name).qty++;
    } else {
        cart.set(name, {
            name: name,
            price: Number(card.dataset.price) || 0,
            qty: 1,
            img: card.dataset.img || ""
        });
    }

    renderCart();
}

// Change quantity
function changeQty(name, change) {
    if (!cart.has(name)) return;

    const item = cart.get(name);
    item.qty += Number(change) || 0;

    if (item.qty <= 0) {
        cart.delete(name);
    }

    renderCart();
}

// Remove item
function removeItem(name) {
    cart.delete(name);
    renderCart();
}

// Render cart
function renderCart() {
    const container = document.getElementById("cartItems");
    if (!container) return;

    container.innerHTML = "";

    if (cart.size === 0) {
        container.innerHTML = `
            <div class="empty-cart">
                <i class="bi bi-cart3"></i>
                Add food items to start billing
            </div>`;
    } else {
        cart.forEach(item => {
            const row = document.createElement("div");
            row.className = "cart-row";

            const product = document.createElement("div");
            product.className = "cart-product";

            const img = document.createElement("img");
            img.src = item.img || "";
            img.alt = item.name;

            const name = document.createElement("strong");
            name.textContent = item.name;

            product.append(img, name);

            const qty = document.createElement("div");
            qty.className = "qty-control";

            const minus = document.createElement("button");
            minus.type = "button";
            minus.textContent = "−";
            minus.setAttribute("aria-label", "Decrease quantity");
            minus.addEventListener("click", () =>
                changeQty(item.name, -1)
            );

            const count = document.createElement("span");
            count.textContent = item.qty;

            const plus = document.createElement("button");
            plus.type = "button";
            plus.textContent = "+";
            plus.setAttribute("aria-label", "Increase quantity");
            plus.addEventListener("click", () =>
                changeQty(item.name, 1)
            );

            qty.append(minus, count, plus);

            const price = document.createElement("span");
            price.className = "cart-price";
            price.textContent = money(item.price);

            const total = document.createElement("span");
            total.className = "cart-total";
            total.textContent = money(item.price * item.qty);

            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "remove-btn";
            remove.innerHTML = '<i class="bi bi-trash3"></i>';
            remove.setAttribute("aria-label", "Remove " + item.name);
            remove.addEventListener("click", () =>
                removeItem(item.name)
            );

            row.append(product, qty, price, total, remove);
            container.appendChild(row);
        });
    }

    calculateTotals();
}

// Calculate totals
function calculateTotals() {
    let subtotal = 0;

    cart.forEach(item => {
        subtotal += item.price * item.qty;
    });

    const discountInput = document.getElementById("discount");
    let discountPercent = Number(discountInput?.value) || 0;

    discountPercent = Math.max(0, Math.min(100, discountPercent));

    const discountAmount = subtotal * discountPercent / 100;
    const taxableAmount = subtotal - discountAmount;

    // Demo tax rates: verify applicable tax setup before production.
    const cgst = taxableAmount * 0.025;
    const sgst = taxableAmount * 0.025;
    const grandTotal = taxableAmount + cgst + sgst;

    const totals = {
        subtotal,
        discountAmount,
        cgst,
        sgst,
        grandTotal
    };

    Object.entries(totals).forEach(([id, value]) => {
        const el = document.getElementById(id);
        if (el) el.textContent = money(value);
    });

    return totals;
}

const discountInput = document.getElementById("discount");

if (discountInput) {
    discountInput.addEventListener("input", calculateTotals);
}

// Order type
document.querySelectorAll(".order-type button").forEach(button => {
    button.addEventListener("click", () => {
        document.querySelectorAll(".order-type button")
            .forEach(btn => btn.classList.remove("active"));

        button.classList.add("active");
        orderType = button.dataset.type || "Dine In";

        const tableField = document.getElementById("tableField");

        if (tableField) {
            tableField.style.display =
                orderType === "Dine In" ? "" : "none";
        }
    });
});

// Clear cart
function clearCart() {
    if (cart.size && !confirm("Clear all items from this bill?")) {
        return;
    }

    cart.clear();

    const discount = document.getElementById("discount");
    const notes = document.getElementById("orderNotes");

    if (discount) discount.value = "0";
    if (notes) notes.value = "";

    renderCart();
}

// Hold current order
function holdOrder() {
    const modalEl = document.getElementById("holdOrdersModal");

    if (!modalEl) {
        alert("HTML-e holdOrdersModal ID-er modal paoa jacche na.");
        return;
    }

    if (cart.size > 0) {
        const order = {
            id: holdOrderCounter++,
            orderType: orderType,
            tableNo: document.getElementById("tableNo")?.value || "",
            guestNo: document.getElementById("guestNo")?.value || "",
            mobile: document.getElementById("customerMobile")?.value || "",
            notes: document.getElementById("orderNotes")?.value || "",
            discount: document.getElementById("discount")?.value || "0",
            items: [...cart.values()].map(item => ({ ...item }))
        };

        heldOrders.push(order);

        cart.clear();

        const discount = document.getElementById("discount");
        const notes = document.getElementById("orderNotes");

        if (discount) discount.value = "0";
        if (notes) notes.value = "";

        renderCart();
    }

    renderHeldOrders();

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } else {
        alert("Bootstrap bundle JavaScript not loaded.");
    }
}

// Render held orders
function renderHeldOrders() {
    const list = document.getElementById("heldOrdersList");
    if (!list) return;

    if (heldOrders.length === 0) {
        list.innerHTML = `
            <div class="text-center text-muted py-4">
                No held orders.
            </div>`;
        return;
    }

    list.innerHTML = heldOrders.map(order => {
        const subtotal = order.items.reduce(
            (sum, item) => sum + item.price * item.qty, 0
        );

        const discountPercent = Math.max(
            0,
            Math.min(100, Number(order.discount) || 0)
        );

        const taxable = subtotal - subtotal * discountPercent / 100;
        const amount = taxable + taxable * 0.025 + taxable * 0.025;

        const itemsHtml = order.items.map(item => `
            <div>
                ${holdEscape(item.name)} × ${item.qty}
                — ${money(item.price * item.qty)}
            </div>
        `).join("");

        return `
            <div class="border rounded-3 p-3 mb-3">
                <label class="d-flex align-items-start gap-3"
                       style="cursor:pointer">

                    <input type="checkbox"
                           class="form-check-input mt-1"
                           onchange="resumeHeldOrder(${order.id}, this.checked)">

                    <span class="flex-grow-1">
                        <strong>
                            ${holdEscape(order.orderType)}
                            — Table No: ${holdEscape(order.tableNo || "-")}
                        </strong>

                        <div>
                            Customer Mobile:
                            ${holdEscape(order.mobile || "-")}
                        </div>

                        <div>
                            Order: ${holdEscape(order.guestNo || "-")}
                        </div>

                        <div class="fw-semibold text-primary mt-1">
                            Amount: ${money(amount)}
                        </div>

                        <hr class="my-2">

                        
                    </span>
                </label>
            </div>`;
    }).join("");
}

// Restore selected held order
function resumeHeldOrder(id, checked) {
    if (!checked) return;

    if (cart.size > 0) {
        alert("Current cart-e items ache. Age current order Hold ba Clear koro.");
        renderHeldOrders();
        return;
    }

    const index = heldOrders.findIndex(
        order => order.id === Number(id)
    );

    if (index < 0) return;

    const order = heldOrders[index];

    cart.clear();

    order.items.forEach(item => {
        cart.set(item.name, { ...item });
    });

    orderType = order.orderType || "Dine In";

    document.querySelectorAll(".order-type button").forEach(btn => {
        btn.classList.toggle(
            "active",
            btn.dataset.type === orderType
        );
    });

    const tableField = document.getElementById("tableField");

    if (tableField) {
        tableField.style.display =
            orderType === "Dine In" ? "" : "none";
    }

    const fields = {
        tableNo: order.tableNo,
        guestNo: order.guestNo,
        customerMobile: order.mobile,
        orderNotes: order.notes,
        discount: order.discount
    };

    Object.entries(fields).forEach(([fieldId, value]) => {
        const el = document.getElementById(fieldId);
        if (el) el.value = value ?? "";
    });

    heldOrders.splice(index, 1);

    renderCart();
    renderHeldOrders();

    const modalEl = document.getElementById("holdOrdersModal");

    if (window.bootstrap && modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    }
}



// Generate demo bill
function generateBill() {
    if (!cart.size) {
        alert("Please add at least one food item.");
        return;
    }

    const items = [...cart.values()].map(item =>
        `${item.name} x ${item.qty} = ${money(item.price * item.qty)}`
    ).join("\n");

    alert(
        "DEMO BILL\n\n" +
        "Order Type: " + orderType + "\n" +
        (orderType === "Dine In"
            ? "Table: " +
              (document.getElementById("tableNo")?.value || "") + "\n"
            : "") +
        "Guest: " +
        (document.getElementById("guestNo")?.value || "") + "\n\n" +
        items + "\n\nGrand Total: " +
        (document.getElementById("grandTotal")?.textContent || money(0)) +
        "\n\nConnect a backend to save and finalize this bill."
    );
}

// Sidebar active state
document.querySelectorAll(".side-link").forEach(link => {
    link.addEventListener("click", function(event) {
        event.preventDefault();

        document.querySelectorAll(".side-link")
            .forEach(item => item.classList.remove("active"));

        this.classList.add("active");
    });
});
