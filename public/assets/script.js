
// ===================== TASTYBITE POS SCRIPT =====================

const cart = new Map();
let activeCategory = "all";
let orderType = "Dine In";
let heldOrders = [];
let holdOrderCounter = 1;

// Currency formatting
const money = value => "₹" + (Number(value) || 0).toLocaleString("en-IN", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
});

// Clock
const dateEl = document.getElementById("currentDate");
const timeEl = document.getElementById("currentTime");
const timeZone = "Asia/Kolkata";
const dateFormatter = dateEl ? new Intl.DateTimeFormat("en-IN", {
    timeZone,
    day: "2-digit",
    month: "short",
    year: "numeric"
}) : null;
const timeFormatter = timeEl ? new Intl.DateTimeFormat("en-US", {
    timeZone,
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: true
}) : null;

function updateClock() {
    const now = new Date();
    const dateText = dateFormatter?.format(now);
    const timeText = timeFormatter?.format(now);

    if (dateEl && dateText && dateEl.textContent !== dateText) {
        dateEl.textContent = dateText;
    }

    if (timeEl && timeText && timeEl.textContent !== timeText) {
        timeEl.textContent = timeText;
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
        activeCategory = button.dataset.category || "all";
        filterMenu();
    });
});

// Menu search
const menuSearch = document.getElementById("menuSearch");
const searchClear = document.querySelector("[data-search-clear]");
const noMatchingProducts = document.getElementById("noMatchingProducts");
const nextBillingCount = document.getElementById("nextBillingCount");

const paymentNotice = document.getElementById("paymentNotice");
const paymentMethodNotices = {
    Cash: "Cash selected. Payment is not recorded or processed by this POS.",
    Card: "Card selected. Use your existing payment terminal; no card details are collected here.",
    UPI: "UPI selected. Use your existing UPI workflow; no payment is processed by this POS."
};

document.querySelectorAll("[data-payment-method]").forEach(button => {
    button.addEventListener("click", () => {
        document.querySelectorAll("[data-payment-method]").forEach(method => {
            const isSelected = method === button;
            method.classList.toggle("active", isSelected);
            method.setAttribute("aria-pressed", String(isSelected));
        });

        if (paymentNotice) {
            const method = button.dataset.paymentMethod || "Cash";
            const noticeText = paymentNotice.querySelector("span");
            if (noticeText) {
                noticeText.textContent = paymentMethodNotices[method] || paymentMethodNotices.Cash;
            }
        }
    });
});

function updateCartItemCount() {
    if (!nextBillingCount) {
        return;
    }

    const itemCount = [...cart.values()].reduce((total, item) => total + item.qty, 0);
    nextBillingCount.textContent = `${itemCount} ${itemCount === 1 ? "item" : "items"}`;
    nextBillingCount.setAttribute("aria-label", `${itemCount} ${itemCount === 1 ? "item" : "items"}`);
}

if (menuSearch) {
    menuSearch.addEventListener("input", () => {
        if (searchClear) {
            searchClear.hidden = menuSearch.value.trim().length === 0;
        }
        filterMenu();
    });

    menuSearch.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
            event.preventDefault();
            filterMenu();
        }
    });
}

if (menuSearch && searchClear) {
    searchClear.addEventListener("click", () => {
        menuSearch.value = "";
        menuSearch.dispatchEvent(new Event("input", { bubbles: true }));
        menuSearch.focus();
    });
}

const categoryScroller = document.getElementById("categoryScroller");
const categoryTrack = document.getElementById("categoryList");

if (categoryScroller && categoryTrack) {
    const updateCategoryArrows = () => {
        const max = categoryTrack.scrollWidth - categoryTrack.clientWidth;
        categoryScroller.classList.toggle("can-scroll-left", categoryTrack.scrollLeft > 4);
        categoryScroller.classList.toggle("can-scroll-right", categoryTrack.scrollLeft < max - 4);
    };

    categoryScroller.querySelectorAll("[data-cat-scroll]").forEach((arrow) => {
        arrow.addEventListener("click", () => {
            categoryTrack.scrollBy({
                left: Number(arrow.dataset.catScroll) * categoryTrack.clientWidth * 0.8,
                behavior: "smooth",
            });
        });
    });

    categoryTrack.addEventListener("wheel", (event) => {
        if (Math.abs(event.deltaY) > Math.abs(event.deltaX) && categoryTrack.scrollWidth > categoryTrack.clientWidth) {
            event.preventDefault();
            categoryTrack.scrollLeft += event.deltaY;
        }
    }, { passive: false });

    categoryTrack.addEventListener("scroll", updateCategoryArrows, { passive: true });
    window.addEventListener("resize", updateCategoryArrows);
    if ("ResizeObserver" in window) {
        new ResizeObserver(updateCategoryArrows).observe(categoryTrack);
    }
    document.querySelectorAll(".category-btn").forEach((button) => {
        button.addEventListener("click", () => {
            button.scrollIntoView({ block: "nearest", inline: "nearest", behavior: "smooth" });
        });
    });
    updateCategoryArrows();
}

const searchSubmit = document.querySelector("[data-search-focus]");
if (menuSearch && searchSubmit) {
    searchSubmit.addEventListener("click", () => {
        filterMenu();
        menuSearch.focus();
    });
}

function filterMenu() {
    const search = (menuSearch?.value || "").trim().replace(/\s+/g, " ").toLocaleLowerCase();
    let visibleCount = 0;

    document.querySelectorAll(".food-card").forEach(card => {
        const matchesCategory =
            activeCategory === "all" ||
            card.dataset.category === activeCategory;

        const searchableText = [
            card.dataset.name || "",
            card.dataset.categoryName || ""
        ].join(" ").toLocaleLowerCase();
        const matchesSearch =
            searchableText.includes(search);

        const isVisible = matchesCategory && matchesSearch;
        card.hidden = !isVisible;
        visibleCount += Number(isVisible);
    });

    if (noMatchingProducts) {
        noMatchingProducts.hidden = visibleCount !== 0;
    }
}

// Add food to cart
function addToCart(button) {
    const card = button?.closest(".food-card");
    if (!card) return;

    const name = card.dataset.name;
    if (!name) return;

    const productId = Number(card.dataset.productId);
    if (!Number.isInteger(productId) || productId <= 0) return;

    if (cart.has(productId)) {
        cart.get(productId).qty++;
    } else {
        cart.set(productId, {
            id: productId,
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
    updateCartItemCount();
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
                changeQty(item.id, -1)
            );

            const count = document.createElement("span");
            count.textContent = item.qty;

            const plus = document.createElement("button");
            plus.type = "button";
            plus.textContent = "+";
            plus.setAttribute("aria-label", "Increase quantity");
            plus.addEventListener("click", () =>
                changeQty(item.id, 1)
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
                removeItem(item.id)
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
        document.querySelectorAll(".order-type button").forEach(btn => {
            btn.classList.remove("active");
            btn.setAttribute("aria-pressed", "false");
        });

        button.classList.add("active");
        button.setAttribute("aria-pressed", "true");
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
    if (cart.size === 0) {
        alert("Add at least one menu item before holding this bill.");
        return;
    }

    heldOrders.push({
        id: holdOrderCounter++,
        orderType,
        tableNo: document.getElementById("tableNo")?.value || "",
        guestNo: document.getElementById("guestNo")?.value || "",
        notes: document.getElementById("orderNotes")?.value || "",
        discount: document.getElementById("discount")?.value || "0",
        items: [...cart.values()].map(item => ({ ...item }))
    });

    cart.clear();
    document.getElementById("discount").value = "0";
    document.getElementById("orderNotes").value = "";
    renderCart();
    renderHeldOrders();
}

// Render held orders
function renderHeldOrders() {
    const list = document.getElementById("heldOrdersList");
    if (!list) return;
    const count = document.getElementById("heldOrderCount");
    if (count) count.textContent = String(heldOrders.length);

    if (heldOrders.length === 0) {
        list.innerHTML = '<p class="held-orders-empty">No held bills.</p>';
        return;
    }

    list.replaceChildren();
    heldOrders.forEach(order => {
        const subtotal = order.items.reduce((sum, item) => sum + item.price * item.qty, 0);
        const discountPercent = Math.max(0, Math.min(100, Number(order.discount) || 0));
        const taxable = subtotal - subtotal * discountPercent / 100;
        const total = taxable * 1.05;

        const card = document.createElement("article");
        card.className = "held-order-card";
        const details = document.createElement("div");
        details.className = "held-order-details";
        const title = document.createElement("strong");
        title.textContent = `${order.orderType} · Table ${order.tableNo || "—"} · ${order.items.length} item${order.items.length === 1 ? "" : "s"}`;
        const amount = document.createElement("span");
        amount.textContent = money(total);
        details.append(title, amount);

        const actions = document.createElement("div");
        actions.className = "held-order-actions";
        const restore = document.createElement("button");
        restore.type = "button";
        restore.className = "held-order-restore";
        restore.textContent = "Restore";
        restore.addEventListener("click", () => resumeHeldOrder(order.id));
        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "held-order-remove";
        remove.textContent = "Remove";
        remove.addEventListener("click", () => {
            heldOrders = heldOrders.filter(held => held.id !== order.id);
            renderHeldOrders();
        });
        actions.append(restore, remove);
        card.append(details, actions);
        list.append(card);
    });
}

// Restore selected held order
function resumeHeldOrder(id) {
    if (cart.size > 0) {
        alert("This bill already has items. Hold or clear it before restoring another bill.");
        return;
    }

    const index = heldOrders.findIndex(
        order => order.id === Number(id)
    );

    if (index < 0) return;

    const order = heldOrders[index];

    cart.clear();

    order.items.forEach(item => {
        cart.set(item.id, { ...item });
    });

    orderType = order.orderType || "Dine In";

    document.querySelectorAll(".order-type button").forEach(btn => {
        const isSelected = btn.dataset.type === orderType;
        btn.classList.toggle("active", isSelected);
        btn.setAttribute("aria-pressed", String(isSelected));
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

}

document.getElementById("holdOrderButton")?.addEventListener("click", holdOrder);

document.getElementById("printBillButton")?.addEventListener("click", () => {
    if (cart.size === 0) {
        alert("Add at least one menu item before printing this bill.");
        return;
    }

    document.body.classList.add("pos-printing");
    window.addEventListener("afterprint", () => {
        document.body.classList.remove("pos-printing");
    }, { once: true });
    window.print();
});

const completeSaleButton = document.getElementById("completeSale");
if (completeSaleButton) {
    completeSaleButton.addEventListener("click", () => {
        if (cart.size === 0) {
            alert("Add at least one menu item before completing the sale.");
            return;
        }
        const form = document.getElementById("completeSaleForm");
        const itemsField = document.getElementById("posItemsJson");
        const discountField = document.getElementById("posDiscountPercent");
        const notesField = document.getElementById("posOrderNotes");
        if (!form || !itemsField || !discountField || !notesField) return;

        itemsField.value = JSON.stringify([...cart.values()].map(item => ({
            menu_item_id: item.id,
            quantity: item.qty
        })));
        discountField.value = String(Math.max(0, Math.min(100, Number(document.getElementById("discount")?.value) || 0)));
        notesField.value = document.getElementById("orderNotes")?.value || "";
        completeSaleButton.disabled = true;
        completeSaleButton.textContent = "Completing...";
        form.submit();
    });
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
