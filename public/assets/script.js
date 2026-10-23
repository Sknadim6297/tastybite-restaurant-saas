
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

document.querySelectorAll("[data-payment-method]").forEach(button => {
    button.addEventListener("click", () => {
        document.querySelectorAll("[data-payment-method]").forEach(method => {
            const isSelected = method === button;
            method.classList.toggle("active", isSelected);
            method.setAttribute("aria-pressed", String(isSelected));
        });

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
        ["floorField", "sectionField"].forEach(id => {
            const field = document.getElementById(id);
            if (field) field.style.display = orderType === "Dine In" ? "" : "none";
        });
    });
});

const posFloor = document.getElementById("posFloor");
const posSection = document.getElementById("posSection");
const posTable = document.getElementById("tableNo");
const posGuests = document.getElementById("guestNo");
const posTableCards = [...document.querySelectorAll("[data-pos-table-card]")];
const tablePickerDialog = document.getElementById("tablePickerDialog");
const openTablePickerButton = document.getElementById("openTablePicker");
const confirmTableSelectionButton = document.getElementById("confirmTableSelection");
const posTableAvailability = document.getElementById("posTableAvailability");
const posTableEmptyState = document.getElementById("posTableEmptyState");
let committedTableSelection = {
    floorId: "",
    sectionId: "",
    tableId: "",
    guestCount: posGuests?.value || "2"
};

function updatePosSectionOptions() {
    if (!posSection || !posFloor) return;
    [...posSection.options].forEach(option => {
        if (option.value) option.hidden = option.dataset.floor !== posFloor.value;
    });
}

function updatePosTableCards() {
    const floorId = posFloor?.value || "";
    const sectionId = posSection?.value || "";
    let visibleCount = 0;
    let selectableCount = 0;

    posTableCards.forEach(card => {
        const matches = Boolean(floorId) && card.dataset.floor === floorId &&
            (!sectionId || card.dataset.section === sectionId);
        card.hidden = !matches;
        if (!matches) return;

        visibleCount++;
        if (!card.disabled) selectableCount++;
        const selected = card.dataset.tableId === posTable?.value;
        card.classList.toggle("is-selected", selected);
        card.setAttribute("aria-pressed", String(selected));
        const statusLabel = card.querySelector("[data-table-status-label]");
        if (statusLabel) {
            statusLabel.textContent = selected
                ? "Selected"
                : (card.dataset.status || "available").replace(/^./, letter => letter.toUpperCase());
        }
    });

    if (posTableAvailability) {
        posTableAvailability.textContent = floorId
            ? `${visibleCount} ${visibleCount === 1 ? "table" : "tables"} · ${selectableCount} selectable`
            : "Choose a floor to view tables.";
    }

    if (posTableEmptyState) {
        posTableEmptyState.hidden = visibleCount > 0;
        if (!floorId && posFloor?.options.length <= 1) {
            document.getElementById("posTableEmptyTitle").textContent = "No tables available";
            document.getElementById("posTableEmptyMessage").textContent = "There are no tables available for POS service right now.";
        } else if (!floorId) {
            document.getElementById("posTableEmptyTitle").textContent = "Choose a floor";
            document.getElementById("posTableEmptyMessage").textContent = "Select a floor to see the tables available for service.";
        } else {
            document.getElementById("posTableEmptyTitle").textContent = "No tables in this view";
            document.getElementById("posTableEmptyMessage").textContent = "Try another section or check table availability.";
        }
    }

    if (confirmTableSelectionButton) {
        const selectedOption = posTable?.selectedOptions[0];
        confirmTableSelectionButton.disabled = !(selectedOption?.value && !selectedOption.disabled);
    }
}

function updateCommittedTableSummary() {
    const selectedOption = posTable?.selectedOptions[0];
    const title = document.getElementById("posTableSelectionTitle");
    const details = document.getElementById("posTableSelectionDetails");
    const selectionButton = openTablePickerButton;
    const floorName = posFloor?.selectedOptions[0]?.textContent?.trim() || "";
    const sectionName = posSection?.value ? posSection.selectedOptions[0]?.textContent?.trim() : "";

    if (!selectedOption?.value) {
        if (title) title.textContent = "Select a table";
        if (details) details.textContent = "Choose a floor and guest count";
        selectionButton?.classList.remove("has-selection");
        return;
    }

    if (title) title.textContent = `Table ${selectedOption.textContent.split("·")[0].trim()}`;
    if (details) {
        details.textContent = [floorName, sectionName, `${posGuests?.value || committedTableSelection.guestCount} guests`]
            .filter(Boolean).join(" · ");
    }
    selectionButton?.classList.add("has-selection");
}

function captureCommittedTableSelection() {
    committedTableSelection = {
        floorId: posFloor?.value || "",
        sectionId: posSection?.value || "",
        tableId: posTable?.value || "",
        guestCount: posGuests?.value || "2"
    };
    updateCommittedTableSummary();
}

function restoreCommittedTableSelection() {
    if (posFloor) posFloor.value = committedTableSelection.floorId;
    if (posSection) posSection.value = committedTableSelection.sectionId;
    if (posTable) posTable.value = committedTableSelection.tableId;
    if (posGuests) posGuests.value = committedTableSelection.guestCount;
    updatePosSectionOptions();
    filterPosTables();
    updateCommittedTableSummary();
}

function filterPosTables() {
    if (!posTable) return;
    const floorId = posFloor?.value || "";
    const sectionId = posSection?.value || "";
    [...posTable.options].forEach(option => {
        if (!option.value) return;
        option.hidden = !floorId || option.dataset.floor !== floorId ||
            (sectionId && option.dataset.section !== sectionId);
    });
    const selected = posTable.selectedOptions[0];
    if (selected?.hidden) posTable.value = "";
    const capacity = Number(posTable.selectedOptions[0]?.dataset.capacity || 20);
    if (posGuests) {
        [...posGuests.options].forEach(option => {
            option.disabled = Number(option.value) > capacity;
        });
        if (Number(posGuests.value) > capacity) posGuests.value = String(capacity);
    }
    updatePosTableCards();
}
posFloor?.addEventListener("change", () => {
    if (posSection) {
        posSection.value = "";
        updatePosSectionOptions();
    }
    filterPosTables();
});
posSection?.addEventListener("change", filterPosTables);
posTable?.addEventListener("change", () => {
    const option = posTable.selectedOptions[0];
    if (option?.value && posFloor) {
        posFloor.value = option.dataset.floor || "";
        if (posSection) {
            [...posSection.options].forEach(section => {
                if (section.value) section.hidden = section.dataset.floor !== posFloor.value;
            });
            posSection.value = option.dataset.section || "";
        }
    }
    filterPosTables();
});

posTableCards.forEach(card => {
    card.addEventListener("click", () => {
        if (card.disabled || !posTable) return;
        posTable.value = card.dataset.tableId || "";
        posTable.dispatchEvent(new Event("change", { bubbles: true }));
    });
});

posGuests?.addEventListener("change", updatePosTableCards);

openTablePickerButton?.addEventListener("click", () => {
    if (!tablePickerDialog?.showModal) return;
    restoreCommittedTableSelection();
    tablePickerDialog.classList.remove("is-closing");
    tablePickerDialog.showModal();
    posFloor?.focus();
});

function closeTablePicker() {
    if (!tablePickerDialog?.open || tablePickerDialog.classList.contains("is-closing")) return;
    tablePickerDialog.classList.add("is-closing");
    window.setTimeout(() => {
        if (tablePickerDialog.open) tablePickerDialog.close();
    }, 160);
}

tablePickerDialog?.addEventListener("cancel", event => {
    event.preventDefault();
    closeTablePicker();
});

tablePickerDialog?.addEventListener("click", event => {
    if (event.target === tablePickerDialog) closeTablePicker();
});

tablePickerDialog?.addEventListener("close", () => {
    tablePickerDialog.classList.remove("is-closing");
    restoreCommittedTableSelection();
    openTablePickerButton?.focus();
});

document.querySelector("[data-close-table-picker]")?.addEventListener("click", closeTablePicker);

confirmTableSelectionButton?.addEventListener("click", () => {
    if (confirmTableSelectionButton.disabled) return;
    captureCommittedTableSelection();
    closeTablePicker();
});

filterPosTables();

// Clear cart
function clearCart() {
    if (cart.size && !confirm("Clear all items from this bill?")) {
        return;
    }

    cart.clear();

    const discount = document.getElementById("discount");

    if (discount) discount.value = "0";

    renderCart();
}

// Hold current order
function holdOrder() {
    if (cart.size === 0) {
        const mostRecentOrder = heldOrders.at(-1);
        if (mostRecentOrder) {
            resumeHeldOrder(mostRecentOrder.id);
            return;
        }
        alert("Add at least one menu item before holding this bill.");
        return;
    }

    heldOrders.push({
        id: holdOrderCounter++,
        orderType,
        tableNo: document.getElementById("tableNo")?.selectedOptions[0]?.textContent?.split("·")[0]?.trim() || "",
        tableId: document.getElementById("tableNo")?.value || "",
        floorId: document.getElementById("posFloor")?.value || "",
        sectionId: document.getElementById("posSection")?.value || "",
        guestNo: document.getElementById("guestNo")?.value || "",
        discount: document.getElementById("discount")?.value || "0",
        items: [...cart.values()].map(item => ({ ...item }))
    });

    cart.clear();
    document.getElementById("discount").value = "0";
    renderCart();
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
    ["floorField", "sectionField"].forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) field.style.display = orderType === "Dine In" ? "" : "none";
    });

    const fields = {
        posFloor: order.floorId,
        posSection: order.sectionId,
        tableNo: order.tableId,
        guestNo: order.guestNo,
        customerMobile: order.mobile,
        discount: order.discount
    };

    Object.entries(fields).forEach(([fieldId, value]) => {
        const el = document.getElementById(fieldId);
        if (el) el.value = value ?? "";
    });
    if (posSection && posFloor) {
        updatePosSectionOptions();
    }
    filterPosTables();
    captureCommittedTableSelection();

    heldOrders.splice(index, 1);

    renderCart();

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
        if (orderType === "Dine In" && tablePickerDialog?.dataset.tableRequired === "true" && !posTable?.value) {
            openTablePickerButton?.click();
            return;
        }
        const form = document.getElementById("completeSaleForm");
        const itemsField = document.getElementById("posItemsJson");
        const discountField = document.getElementById("posDiscountPercent");
        const orderTypeField = document.getElementById("posOrderType");
        const tableField = document.getElementById("posTableNumber");
        const tableIdField = document.getElementById("posTableId");
        const floorIdField = document.getElementById("posFloorId");
        const sectionIdField = document.getElementById("posSectionId");
        const guestField = document.getElementById("posGuestCount");
        const paymentField = document.getElementById("posPaymentMethod");
        if (!form || !itemsField || !discountField || !orderTypeField || !tableField || !guestField || !paymentField) return;

        itemsField.value = JSON.stringify([...cart.values()].map(item => ({
            menu_item_id: item.id,
            quantity: item.qty
        })));
        discountField.value = String(Math.max(0, Math.min(100, Number(document.getElementById("discount")?.value) || 0)));
        orderTypeField.value = ({
            "Dine In": "dine_in",
            "Take Away": "takeaway",
            Delivery: "delivery"
        })[orderType] || "dine_in";
        const tableSelect = document.getElementById("tableNo");
        const selectedTable = tableSelect?.selectedOptions[0];
        tableIdField.value = orderType === "Dine In" ? (tableSelect?.value || "") : "";
        floorIdField.value = orderType === "Dine In" ? (document.getElementById("posFloor")?.value || "") : "";
        sectionIdField.value = orderType === "Dine In" ? (document.getElementById("posSection")?.value || "") : "";
        tableField.value = orderType === "Dine In" ? (selectedTable?.textContent?.split("·")[0]?.trim() || "") : "";
        guestField.value = document.getElementById("guestNo")?.value || "";
        const selectedPaymentMethod = document.querySelector("[data-payment-method].active")?.dataset.paymentMethod;
        paymentField.value = selectedPaymentMethod?.toLowerCase() || "";
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
