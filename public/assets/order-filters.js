(() => {
    const form = document.querySelector('#order-filters');
    const results = document.querySelector('#order-results');
    const status = document.querySelector('#order-filter-status');

    if (!(form instanceof HTMLFormElement) || !(results instanceof HTMLElement) || !(status instanceof HTMLElement)) return;

    let searchTimer = null;
    let activeRequest = null;
    let requestVersion = 0;

    const setLoading = (loading, message = 'Loading orders...') => {
        status.hidden = !loading;
        status.textContent = message;
        form.setAttribute('aria-busy', String(loading));
        results.setAttribute('aria-busy', String(loading));
    };

    const currentFiltersUrl = () => {
        const url = new URL(window.location.href);
        url.search = '';
        form.querySelectorAll('input[name], select[name]').forEach((field) => {
            if (field.value.trim() !== '') url.searchParams.set(field.name, field.value.trim());
        });
        url.searchParams.set('admin_access', new URL(window.location.href).searchParams.get('admin_access') || '');
        if (!url.searchParams.get('admin_access')) url.searchParams.delete('admin_access');
        url.searchParams.delete('page');
        return url;
    };

    const validDateRange = () => {
        const from = form.querySelector('[name="from"]')?.value || '';
        const to = form.querySelector('[name="to"]')?.value || '';
        return !from || !to || from <= to;
    };

    const loadOrders = async (url, { addHistory = true } = {}) => {
        if (!validDateRange()) {
            setLoading(true, 'The end date must be on or after the start date.');
            return;
        }

        const requestUrl = new URL(url, window.location.href);
        if (requestUrl.origin !== window.location.origin || requestUrl.pathname !== window.location.pathname) return;

        activeRequest?.abort();
        activeRequest = new AbortController();
        const currentRequest = ++requestVersion;
        setLoading(true);

        if (addHistory && requestUrl.href !== window.location.href) {
            window.history.pushState({ orderFilters: true }, '', requestUrl);
        }

        try {
            const response = await fetch(requestUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: activeRequest.signal,
            });
            if (!response.ok) throw new Error(`Order filtering failed with HTTP ${response.status}.`);
            const payload = await response.json();
            if (currentRequest !== requestVersion) return;
            if (typeof payload.html !== 'string') throw new Error('The order filter response was malformed.');

            results.innerHTML = payload.html;
            form.querySelectorAll('input[name], select[name]').forEach((field) => {
                field.value = requestUrl.searchParams.get(field.name) || '';
            });
            setLoading(false);
        } catch (error) {
            if (error.name === 'AbortError' || currentRequest !== requestVersion) return;
            console.error('Unable to load filtered orders.', error);
            setLoading(true, 'Unable to load orders. Please try again.');
            form.setAttribute('aria-busy', 'false');
            results.setAttribute('aria-busy', 'false');
        }
    };

    const applyFilters = () => {
        window.clearTimeout(searchTimer);
        loadOrders(currentFiltersUrl());
    };

    form.addEventListener('submit', (event) => event.preventDefault());
    form.querySelectorAll('input[type="search"]').forEach((input) => {
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(applyFilters, 300);
        });
    });

    form.querySelectorAll('select').forEach((select) => select.addEventListener('change', applyFilters));
    form.querySelectorAll('input:not([type="search"])').forEach((input) => {
        input.addEventListener('change', applyFilters);
    });

    form.querySelector('#clear-order-filters')?.addEventListener('click', () => {
        form.querySelectorAll('input:not([type="hidden"]), select').forEach((field) => {
            field.value = '';
        });
        applyFilters();
    });

    results.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const link = target.closest('.tb-pagination a, .pagination a, nav[role="navigation"] a');
        if (!link || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;
        event.preventDefault();
        window.clearTimeout(searchTimer);
        loadOrders(url);
    });

    window.addEventListener('popstate', () => {
        window.clearTimeout(searchTimer);
        const url = new URL(window.location.href);
        form.querySelectorAll('input[name], select[name]').forEach((field) => {
            field.value = url.searchParams.get(field.name) || '';
        });
        loadOrders(url, { addHistory: false });
    });
})();
