(() => {
    const form = document.querySelector('[data-purchase-order-form]');
    if (!form) return;

    const items = form.querySelector('[data-po-items]');
    const template = form.querySelector('[data-po-item-template]');
    let nextIndex = Math.max(-1, ...[...items.querySelectorAll('[data-po-item] input[name]')]
        .map((input) => Number(input.name.match(/^items\[(\d+)\]/)?.[1] ?? -1))) + 1;

    const calculate = () => {
        let subtotal = 0;
        let discount = 0;
        let tax = 0;
        items.querySelectorAll('[data-po-item]').forEach((row) => {
            const quantity = Number(row.querySelector('[name$="[quantity]"]')?.value) || 0;
            const unitPrice = Number(row.querySelector('[name$="[unit_price]"]')?.value) || 0;
            const lineDiscount = Number(row.querySelector('[name$="[discount_amount]"]')?.value) || 0;
            const taxRate = Number(row.querySelector('[name$="[tax_rate]"]')?.value) || 0;
            const gross = quantity * unitPrice;
            const lineTax = Math.max(0, gross - lineDiscount) * Math.min(100, Math.max(0, taxRate)) / 100;
            subtotal += gross;
            discount += lineDiscount;
            tax += lineTax;
        });
        const format = (amount) => amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        form.querySelector('[data-po-subtotal]').textContent = format(subtotal);
        form.querySelector('[data-po-discount]').textContent = format(discount);
        form.querySelector('[data-po-tax]').textContent = format(tax);
        form.querySelector('[data-po-total]').textContent = format(subtotal - discount + tax);
    };

    form.querySelector('[data-add-po-item]')?.addEventListener('click', () => {
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-po-item]');
        row.querySelectorAll('[data-field]').forEach((input) => {
            input.name = `items[${nextIndex}][${input.dataset.field}]`;
        });
        nextIndex += 1;
        items.append(fragment);
        calculate();
    });

    items.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-po-item]');
        if (!remove) return;
        const rows = items.querySelectorAll('[data-po-item]');
        const row = remove.closest('[data-po-item]');
        if (rows.length > 1) {
            row.remove();
        } else {
            row.querySelectorAll('input').forEach((input) => {
                if (input.name.endsWith('[unit]')) input.value = 'unit';
                else if (input.name.endsWith('[discount_amount]') || input.name.endsWith('[tax_rate]')) input.value = '0.00';
                else input.value = '';
            });
        }
        calculate();
    });

    items.addEventListener('input', calculate);
    calculate();
})();
