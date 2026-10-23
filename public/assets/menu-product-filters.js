(() => {
    const form = document.querySelector('#product-filters');
    const results = document.querySelector('#product-results');
    const search = document.querySelector('#product-search');
    const category = document.querySelector('#product-category');
    const clearButton = document.querySelector('#clear-product-filters');
    const status = document.querySelector('#product-filter-status');

    if (!(form instanceof HTMLFormElement)
        || !(results instanceof HTMLElement)
        || !(search instanceof HTMLInputElement)
        || !(category instanceof HTMLSelectElement)
        || !(clearButton instanceof HTMLButtonElement)
        || !(status instanceof HTMLElement)) {
        return;
    }

    let searchTimer = null;
    let activeRequest = null;
    let requestVersion = 0;

    const syncControls = (url) => {
        search.value = url.searchParams.get('search') || '';
        category.value = url.searchParams.get('category_id') || '';
    };

    const setLoading = (loading) => {
        status.hidden = !loading;
        form.setAttribute('aria-busy', String(loading));
        results.setAttribute('aria-busy', String(loading));
    };

    const loadResults = async (url, { addHistory = true } = {}) => {
        const requestUrl = new URL(url, window.location.href);
        if (requestUrl.origin !== window.location.origin || requestUrl.pathname !== window.location.pathname) {
            return;
        }

        activeRequest?.abort();
        activeRequest = new AbortController();
        const currentRequest = ++requestVersion;
        setLoading(true);
        status.textContent = 'Loading products...';

        if (addHistory && requestUrl.href !== window.location.href) {
            window.history.pushState({ productFilters: true }, '', requestUrl);
        }

        try {
            const response = await fetch(requestUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: activeRequest.signal,
            });

            if (!response.ok) {
                throw new Error(`Product filtering failed with HTTP ${response.status}.`);
            }

            const payload = await response.json();
            if (currentRequest !== requestVersion) return;
            if (typeof payload.html !== 'string') {
                throw new Error('The product filter response did not contain the expected results.');
            }

            results.innerHTML = payload.html;
            syncControls(requestUrl);
            setLoading(false);
        } catch (error) {
            if (error.name === 'AbortError' || currentRequest !== requestVersion) return;

            console.error('Unable to load filtered products.', error);
            status.textContent = 'Unable to load products. Please try again.';
            form.setAttribute('aria-busy', 'false');
            results.setAttribute('aria-busy', 'false');
        }
    };

    const filteredUrl = () => {
        const url = new URL(window.location.href);
        url.searchParams.delete('search');
        url.searchParams.delete('category_id');
        url.searchParams.delete('page');

        if (search.value.trim()) url.searchParams.set('search', search.value.trim());
        if (category.value) url.searchParams.set('category_id', category.value);

        return url;
    };

    form.addEventListener('submit', (event) => event.preventDefault());

    search.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => loadResults(filteredUrl()), 300);
    });

    category.addEventListener('change', () => {
        window.clearTimeout(searchTimer);
        loadResults(filteredUrl());
    });

    clearButton.addEventListener('click', () => {
        window.clearTimeout(searchTimer);
        search.value = '';
        category.value = '';
        loadResults(filteredUrl());
        search.focus();
    });

    results.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const link = target.closest('.tb-pagination a, .pagination a');
        if (!link || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname) return;

        event.preventDefault();
        window.clearTimeout(searchTimer);
        loadResults(url);
    });

    window.addEventListener('popstate', () => {
        window.clearTimeout(searchTimer);
        const url = new URL(window.location.href);
        syncControls(url);
        loadResults(url, { addHistory: false });
    });
})();
