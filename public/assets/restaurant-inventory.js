document.addEventListener('DOMContentLoaded', () => {
    const printButton = document.querySelector('[data-print-report]');
    printButton?.addEventListener('click', () => window.print());

    document.querySelectorAll('form[data-disable-on-submit]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }

            form.dataset.submitting = 'true';
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                if (button.dataset.submitLabel) {
                    button.dataset.originalLabel = button.textContent.trim();
                    button.textContent = button.dataset.submitLabel;
                }
            });
        });
    });

    const receiptForm = document.querySelector('[data-stock-receipt-form]');
    if (receiptForm) {
        const item = receiptForm.querySelector('[data-stock-item]');
        const location = receiptForm.querySelector('[data-stock-location]');
        const unit = receiptForm.querySelector('[data-stock-unit]');
        const quantity = receiptForm.querySelector('[data-stock-quantity]');
        const unitCost = receiptForm.querySelector('[data-stock-unit-cost]');
        const batchNumber = receiptForm.querySelector('[data-stock-batch-number]');
        const expiry = receiptForm.querySelector('[data-stock-expiry]');
        const batchField = receiptForm.querySelector('[data-stock-batch-field]');
        const manufacturingField = receiptForm.querySelector('[data-stock-manufactured-field]');
        const expiryField = receiptForm.querySelector('[data-stock-expiry-field]');
        const summary = {
            item: receiptForm.querySelector('[data-summary-item]'),
            quantity: receiptForm.querySelector('[data-summary-quantity]'),
            unitCost: receiptForm.querySelector('[data-summary-unit-cost]'),
            location: receiptForm.querySelector('[data-summary-location]'),
            total: receiptForm.querySelector('[data-summary-total]'),
        };
        const currency = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' });
        const formatAmount = (value) => currency.format(Number.isFinite(value) ? value : 0);
        const updateTrackingFields = () => {
            const selected = item.selectedOptions[0];
            const tracksBatches = selected?.dataset.trackBatches === '1';
            const tracksExpiry = selected?.dataset.trackExpiry === '1';
            const requiresBatch = tracksBatches || tracksExpiry;
            const requiresExpiry = tracksExpiry;

            batchField.hidden = !requiresBatch;
            manufacturingField.hidden = !requiresBatch;
            expiryField.hidden = !requiresExpiry;
            batchNumber.required = requiresBatch;
            expiry.required = tracksExpiry;
            const expiryMark = expiryField.querySelector('label span');
            expiryMark.className = tracksExpiry ? 'required-mark' : 'field-optional';
            expiryMark.textContent = tracksExpiry ? '*' : 'Optional';
        };
        const updateSummary = () => {
            const itemOption = item.selectedOptions[0];
            const unitOption = unit.selectedOptions[0];
            const selectedQuantity = Number(quantity.value);
            const selectedCost = Number(unitCost.value);
            const unitLabel = unitOption?.dataset.abbreviation || '';

            summary.item.textContent = itemOption?.textContent.trim() || '—';
            summary.quantity.textContent = Number.isFinite(selectedQuantity) && selectedQuantity > 0
                ? `${selectedQuantity.toLocaleString(undefined, { maximumFractionDigits: 6 })} ${unitLabel}`
                : '—';
            summary.unitCost.textContent = formatAmount(Number.isFinite(selectedCost) ? selectedCost : 0);
            summary.location.textContent = location.selectedOptions[0]?.textContent.trim() || '—';
            summary.total.textContent = formatAmount(
                Number.isFinite(selectedQuantity) && Number.isFinite(selectedCost) ? selectedQuantity * selectedCost : 0,
            );
        };

        [item, location, unit].forEach((field) => field.addEventListener('change', updateSummary));
        item.addEventListener('change', updateTrackingFields);
        [quantity, unitCost].forEach((field) => field.addEventListener('input', updateSummary));
        updateTrackingFields();
        updateSummary();
    }

    const rows = document.getElementById('recipe-ingredient-rows');
    const template = document.getElementById('recipe-ingredient-template');
    const addButton = document.getElementById('add-recipe-ingredient');

    if (!rows || !template || !addButton) {
        return;
    }

    addButton.addEventListener('click', () => {
        const index = rows.querySelectorAll('.recipe-ingredient-row').length;
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name], [id], label[for]').forEach((element) => {
            ['name', 'id', 'for'].forEach((attribute) => {
                if (element.hasAttribute(attribute)) {
                    element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', index));
                }
            });
        });
        rows.append(fragment);
    });

    rows.addEventListener('click', (event) => {
        if (event.target.closest('.remove-recipe-ingredient')) {
            event.target.closest('.recipe-ingredient-row').remove();
        }
    });
});
