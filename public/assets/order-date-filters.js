(() => {
    document.querySelectorAll('.order-date-filter').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) return;

        const period = form.querySelector('[name="period"]');
        const from = form.querySelector('[name="from"]');
        const to = form.querySelector('[name="to"]');

        const submitIfValid = () => {
            if (from && to && from.value && to.value && from.value > to.value) {
                to.setCustomValidity('The end date must be on or after the start date.');
                to.reportValidity();
                return;
            }
            to?.setCustomValidity('');
            form.requestSubmit();
        };

        period?.addEventListener('change', () => {
            if (period.value !== 'custom') {
                if (from) from.value = '';
                if (to) to.value = '';
            }
            submitIfValid();
        });
        [from, to].filter(Boolean).forEach((input) => input.addEventListener('change', () => {
            if (period) period.value = 'custom';
            submitIfValid();
        }));
    });
})();
