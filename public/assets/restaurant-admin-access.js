(() => {
    const script = document.currentScript;
    const accessToken = script?.dataset.accessToken;
    const parameter = 'admin_access';

    if (!accessToken) {
        return;
    }

    const preserveContext = (rawUrl) => {
        try {
            const url = new URL(rawUrl, window.location.href);
            if (url.origin !== window.location.origin) {
                return rawUrl;
            }

            url.searchParams.set(parameter, accessToken);
            return url.toString();
        } catch (error) {
            console.error('Unable to preserve the restaurant access context.', error);
            return rawUrl;
        }
    };

    const updateNavigation = (root = document) => {
        const elements = [];
        if (root instanceof Element && root.matches('a[href], form[action]')) {
            elements.push(root);
        }
        elements.push(...root.querySelectorAll('a[href], form[action]'));

        elements.forEach((element) => {
            const attribute = element.tagName === 'A' ? 'href' : 'action';
            element.setAttribute(attribute, preserveContext(element.getAttribute(attribute)));
        });
    };

    updateNavigation();

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : event.target.parentElement;
        const link = target?.closest('a[href]');
        if (link) {
            link.href = preserveContext(link.href);
        }
    }, true);

    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement) {
            event.target.action = preserveContext(event.target.action);
        }
    }, true);

    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    updateNavigation(node);
                }
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
