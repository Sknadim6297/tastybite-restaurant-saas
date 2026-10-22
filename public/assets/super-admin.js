document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form[data-subscription-payment-form]').forEach((form) => {
        const restaurantSelect = form.querySelector('[data-payment-restaurant]');
        const subscriptionSelect = form.querySelector('[data-payment-subscription]');
        const loader = form.querySelector('[data-payment-loader]');
        const loadError = form.querySelector('[data-payment-load-error]');
        const emptyMessage = form.querySelector('[data-payment-empty]');
        const submitButton = form.querySelector('[data-payment-submit]');
        const amountInput = form.querySelector('[data-payment-amount]');
        const values = {
            restaurantName: form.querySelector('[data-payment-restaurant-name]'),
            restaurantId: form.querySelector('[data-payment-restaurant-id]'),
            restaurantEmail: form.querySelector('[data-payment-restaurant-email]'),
            restaurantPhone: form.querySelector('[data-payment-restaurant-phone]'),
            ownerName: form.querySelector('[data-payment-owner-name]'),
            ownerEmail: form.querySelector('[data-payment-owner-email]'),
            ownerMobile: form.querySelector('[data-payment-owner-mobile]'),
            ownerEdit: form.querySelector('[data-payment-owner-edit]'),
            subscriptionId: form.querySelector('[data-payment-subscription-id]'),
            planName: form.querySelector('[data-payment-plan-name]'),
            planCode: form.querySelector('[data-payment-plan-code]'),
            planPrice: form.querySelector('[data-payment-plan-price]'),
            duration: form.querySelector('[data-payment-duration]'),
            start: form.querySelector('[data-payment-start]'),
            expiry: form.querySelector('[data-payment-expiry]'),
            status: form.querySelector('[data-payment-status]'),
            total: form.querySelector('[data-payment-total]'),
            paid: form.querySelector('[data-payment-paid]'),
            outstanding: form.querySelector('[data-payment-outstanding]'),
            pending: form.querySelector('[data-payment-pending]'),
            balanceStatus: form.querySelector('[data-payment-balance-status]'),
        };
        let requestController = null;
        let restaurantData = null;

        const restaurantDetailValues = [
            values.restaurantName,
            values.restaurantId,
            values.restaurantEmail,
            values.restaurantPhone,
            values.ownerName,
            values.ownerEmail,
        ];
        const subscriptionDetailValues = [
            values.subscriptionId,
            values.planName,
            values.planCode,
            values.planPrice,
            values.duration,
            values.start,
            values.expiry,
            values.status,
            values.total,
            values.paid,
            values.outstanding,
            values.pending,
            values.balanceStatus,
        ];

        const clearRestaurantDetails = () => {
            restaurantDetailValues.forEach((element) => {
                element.textContent = '—';
            });
            values.ownerMobile.textContent = 'Not provided';
            values.ownerEdit.hidden = true;
            values.ownerEdit.removeAttribute('href');
        };

        const clearSubscriptionDetails = () => {
            subscriptionDetailValues.forEach((element) => {
                element.textContent = '—';
            });
            values.status.className = 'status-pill';
            values.balanceStatus.className = 'status-pill';
            form.querySelector('[data-payment-fully-paid]').hidden = true;
            form.querySelector('[data-payment-no-available]').hidden = true;
            submitButton.disabled = true;
        };

        const clearDetails = () => {
            clearRestaurantDetails();
            clearSubscriptionDetails();
        };

        const displayValue = (value, fallback = 'Not available') => {
            if (typeof value !== 'string' || value.trim() === '') return fallback;
            if (['null', 'undefined', 'invalid date'].includes(value.trim().toLowerCase())) return fallback;

            return value;
        };
        const currencyAmount = (currency, amount) => {
            const numericAmount = Number(amount);
            if (!Number.isFinite(numericAmount)) return 'Not available';
            const formattedAmount = numericAmount.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });

            return currency === 'INR' ? `₹${formattedAmount}` : `${displayValue(currency)} ${formattedAmount}`;
        };
        const statusClass = (status) => `status-pill status-${String(status || '').replaceAll('_', '-')}`;

        const displaySubscription = () => {
            const subscription = restaurantData?.subscriptions.find((item) => String(item.id) === subscriptionSelect.value);
            clearSubscriptionDetails();
            if (!subscription) return;

            values.subscriptionId.textContent = `#${subscription.id}`;
            values.planName.textContent = displayValue(subscription.plan_name);
            values.planCode.textContent = displayValue(subscription.plan_code);
            values.planPrice.textContent = currencyAmount(subscription.currency, subscription.price);
            values.duration.textContent = displayValue(subscription.billing_duration);
            const activationPending = ['pending_payment', 'pending_approval'].includes(subscription.status);
            const dateFallback = activationPending ? 'Not Activated Yet' : 'Not available';
            values.start.textContent = displayValue(subscription.starts_at, dateFallback);
            values.expiry.textContent = displayValue(subscription.expires_at, dateFallback);
            values.status.textContent = displayValue(subscription.status_label);
            values.status.className = statusClass(subscription.status);
            values.total.textContent = currencyAmount(subscription.currency, subscription.total_payable);
            values.paid.textContent = currencyAmount(subscription.currency, subscription.already_paid);
            values.outstanding.textContent = currencyAmount(subscription.currency, subscription.outstanding);
            values.pending.textContent = currencyAmount(subscription.currency, subscription.pending_amount);
            values.balanceStatus.textContent = displayValue(subscription.payment_status);
            values.balanceStatus.className = statusClass(subscription.payment_status);

            const fullyPaid = subscription.outstanding === '0.00';
            const available = Number(subscription.available_amount);
            form.querySelector('[data-payment-fully-paid]').hidden = !fullyPaid;
            form.querySelector('[data-payment-no-available]').hidden = fullyPaid || available > 0;
            amountInput.max = subscription.available_amount;
            if (
                amountInput.value === ''
                || amountInput.value === amountInput.dataset.paymentAutoAmount
            ) {
                amountInput.value = available > 0 ? subscription.available_amount : '';
                amountInput.dataset.paymentAutoAmount = amountInput.value;
            }
            submitButton.disabled = fullyPaid || available <= 0;
        };

        const loadSubscriptions = async (restaurantId, selectedSubscriptionId = '') => {
            requestController?.abort();
            requestController = null;
            restaurantData = null;
            subscriptionSelect.replaceChildren(new Option('Loading subscriptions...', ''));
            subscriptionSelect.disabled = true;
            loader.hidden = !restaurantId;
            loadError.hidden = true;
            loadError.textContent = '';
            emptyMessage.hidden = true;
            clearDetails();
            form.setAttribute('aria-busy', String(Boolean(restaurantId)));
            if (!restaurantId) {
                subscriptionSelect.replaceChildren(new Option('Select a restaurant first', ''));
                form.removeAttribute('aria-busy');
                return;
            }

            const controller = new AbortController();
            requestController = controller;
            try {
                const url = form.dataset.subscriptionsUrl.replace('__RESTAURANT__', encodeURIComponent(restaurantId));
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                const payload = await response.json();
                if (!response.ok) {
                    throw new Error(payload.message || 'Could not load subscription details. Please try again.');
                }

                restaurantData = payload;
                const restaurant = payload.restaurant;
                const owner = payload.owner;
                values.restaurantName.textContent = displayValue(restaurant.name);
                values.restaurantId.textContent = `#${restaurant.id}`;
                values.restaurantEmail.textContent = displayValue(restaurant.email);
                values.restaurantPhone.textContent = displayValue(restaurant.phone);
                values.ownerName.textContent = displayValue(owner?.name, 'No owner listed');
                values.ownerEmail.textContent = displayValue(owner?.email);
                values.ownerMobile.textContent = displayValue(owner?.mobile, 'Not provided');
                values.ownerEdit.hidden = !owner?.edit_url;
                if (owner?.edit_url) values.ownerEdit.href = owner.edit_url;

                subscriptionSelect.replaceChildren(new Option(
                    payload.subscriptions.length ? 'Select an existing subscription' : 'No subscription found for this restaurant.',
                    '',
                ));
                payload.subscriptions.forEach((subscription) => {
                    const amount = currencyAmount(subscription.currency, subscription.price);
                    const option = new Option(
                        `SUB #${subscription.id} — ${displayValue(subscription.plan_name)} — ${amount} — ${displayValue(subscription.status_label)}`,
                        String(subscription.id),
                    );
                    subscriptionSelect.add(option);
                });
                subscriptionSelect.disabled = payload.subscriptions.length === 0;
                emptyMessage.hidden = payload.subscriptions.length !== 0;
                if (selectedSubscriptionId && payload.subscriptions.some((item) => String(item.id) === String(selectedSubscriptionId))) {
                    subscriptionSelect.value = String(selectedSubscriptionId);
                }
                displaySubscription();
            } catch (error) {
                if (error.name !== 'AbortError' && requestController === controller) {
                    subscriptionSelect.replaceChildren(new Option('Unable to load subscriptions', ''));
                    loadError.textContent = error.message || 'Could not load subscription details. Please try again.';
                    loadError.hidden = false;
                }
            } finally {
                if (requestController === controller) {
                    loader.hidden = true;
                    form.removeAttribute('aria-busy');
                }
            }
        };

        subscriptionSelect.addEventListener('change', displaySubscription);
        restaurantSelect.addEventListener('change', () => {
            amountInput.value = '';
            delete amountInput.dataset.paymentAutoAmount;
            loadSubscriptions(restaurantSelect.value);
        });
        amountInput.addEventListener('input', () => {
            if (amountInput.value !== amountInput.dataset.paymentAutoAmount) {
                delete amountInput.dataset.paymentAutoAmount;
            }
        });

        if (restaurantSelect.value) {
            loadSubscriptions(restaurantSelect.value, form.dataset.oldSubscription);
            amountInput.value = form.dataset.oldAmount;
        } else {
            clearDetails();
        }
    });

    document.querySelectorAll('form[data-subscription-expiry-preview]').forEach((form) => {
        const planSelect = form.querySelector('[name="subscription_plan_id"]');
        const startInput = form.querySelector('[name="starts_at"]');
        const trialInput = form.querySelector('input[type="checkbox"][name="trial"]');
        const preview = form.querySelector('[data-expiry-preview]');
        const summary = form.querySelector('[data-duration-summary]');

        const refreshPreview = () => {
            const option = planSelect.selectedOptions[0];
            const startParts = startInput.value.split('-').map(Number);
            preview.value = '';

            if (!option?.value || startParts.length !== 3 || startParts.some(Number.isNaN)) {
                summary.textContent = 'Select a plan and start date to preview its duration and expiry.';
                return;
            }

            const months = Number(option.dataset.durationMonths || 0);
            const days = Number(option.dataset.durationDays || 0);
            const period = option.dataset.billingPeriod;
            const paidDuration = period === 'monthly'
                ? '1 calendar month'
                : period === 'quarterly'
                    ? '3 calendar months'
                    : period === 'yearly'
                        ? '12 calendar months'
                        : months
                            ? `${months} calendar month${months === 1 ? '' : 's'}`
                            : `${days} day${days === 1 ? '' : 's'}`;
            let termMonths = months;
            let termDays = days;
            let durationLabel = paidDuration;

            if (trialInput.checked) {
                const trialDays = Number(option.dataset.trialDurationDays || 0);
                if (option.dataset.trialAvailable !== '1' || !trialDays) {
                    summary.textContent = `Trial is not available for this plan. Paid plan duration: ${paidDuration}.`;
                    return;
                }

                termMonths = 0;
                termDays = trialDays;
                durationLabel = `Trial · ${trialDays} days (paid plan: ${paidDuration})`;
            }

            const [year, month, day] = startParts;
            let expiry;
            if (termDays > 0) {
                expiry = new Date(Date.UTC(year, month - 1, day + termDays - 1));
            } else if (termMonths > 0) {
                const monthIndex = month - 1 + termMonths;
                const expiryYear = year + Math.floor(monthIndex / 12);
                const expiryMonth = monthIndex % 12;
                const lastDay = new Date(Date.UTC(expiryYear, expiryMonth + 1, 0)).getUTCDate();
                expiry = new Date(Date.UTC(expiryYear, expiryMonth, Math.min(day, lastDay) - 1));
            } else {
                summary.textContent = 'The selected plan has no valid duration.';
                return;
            }

            preview.value = `${expiry.getUTCFullYear()}-${String(expiry.getUTCMonth() + 1).padStart(2, '0')}-${String(expiry.getUTCDate()).padStart(2, '0')}`;
            summary.textContent = `Duration: ${durationLabel}. Expiry is inclusive through the end of the displayed date.`;
        };

        planSelect.addEventListener('change', refreshPreview);
        startInput.addEventListener('input', refreshPreview);
        trialInput.addEventListener('change', refreshPreview);
        refreshPreview();
    });

    document.querySelectorAll('form[data-plan-duration-form]').forEach((form) => {
        const period = form.querySelector('[name="billing_period"]');
        const unit = form.querySelector('[data-custom-duration-unit]');
        const customFields = form.querySelector('[data-custom-duration-fields]');
        const standardDuration = form.querySelector('[data-standard-duration]');
        const trialAvailable = form.querySelector('[data-trial-available]');
        const trialDuration = form.querySelector('[data-trial-duration-field]');
        const updatePlanFields = () => {
            const custom = period.value === 'custom';
            customFields.hidden = !custom;
            standardDuration.hidden = custom;
            customFields.querySelectorAll('select').forEach((field) => {
                field.disabled = !custom;
            });

            form.querySelectorAll('[data-duration-input]').forEach((field) => {
                const active = custom && field.dataset.durationInput === unit.value;
                field.hidden = !active;
                field.querySelector('input').disabled = !active;
                field.querySelector('input').required = active;
            });

            const hasTrial = trialAvailable.checked;
            trialDuration.closest('.form-field').hidden = !hasTrial;
            trialDuration.disabled = !hasTrial;
            trialDuration.required = hasTrial;
        };

        period.addEventListener('change', updatePlanFields);
        unit.addEventListener('change', updatePlanFields);
        trialAvailable.addEventListener('change', updatePlanFields);
        updatePlanFields();
    });

    const searchFocusStorageKey = 'tastybite.auto-filter-search-focus';
    const navigationEntry = performance.getEntriesByType('navigation')[0];
    const restoreSearchFocus = () => {
        const serialized = sessionStorage.getItem(searchFocusStorageKey);
        if (!serialized) return;

        sessionStorage.removeItem(searchFocusStorageKey);

        let saved;
        try {
            saved = JSON.parse(serialized);
        } catch {
            return;
        }

        if (
            navigationEntry?.type !== 'navigate'
            || Date.now() - saved.savedAt > 15000
            || saved.path !== window.location.pathname
            || (new URL(window.location.href).searchParams.get(saved.name) || '') !== saved.value
            || ![document.body, document.documentElement].includes(document.activeElement)
        ) {
            return;
        }

        const input = [...document.querySelectorAll('form[data-auto-filter][method="get" i] input[type="search"], form[data-auto-filter][method="get" i] input[data-auto-search]')]
            .find((candidate) => candidate.name === saved.name && candidate.id === saved.id);

        if (!input || input.value !== saved.value) return;

        input.focus({ preventScroll: true });
        input.setSelectionRange(
            Math.min(saved.selectionStart, input.value.length),
            Math.min(saved.selectionEnd, input.value.length),
            saved.selectionDirection,
        );
    };

    restoreSearchFocus();

    const filterForms = [...document.querySelectorAll('form[data-auto-filter][method="get" i]')];
    const content = document.querySelector('.admin-content');
    let filterLoader = null;
    let showLoaderTimer = null;
    let hideLoaderTimer = null;
    let navigationPending = false;

    if (filterForms.length && content) {
        filterLoader = document.createElement('div');
        filterLoader.className = 'auto-filter-loader';
        filterLoader.setAttribute('role', 'status');
        filterLoader.setAttribute('aria-live', 'polite');
        filterLoader.hidden = true;
        filterLoader.innerHTML = '<span class="auto-filter-spinner" aria-hidden="true"></span><span>Loading...</span>';
        content.prepend(filterLoader);

        const hideFilterLoader = () => {
            window.clearTimeout(showLoaderTimer);
            window.clearTimeout(hideLoaderTimer);
            showLoaderTimer = null;
            hideLoaderTimer = null;
            navigationPending = false;
            filterLoader.hidden = true;
            content.removeAttribute('aria-busy');
        };

        const showFilterLoader = () => {
            if (navigationPending) return;
            navigationPending = true;
            content.setAttribute('aria-busy', 'true');
            showLoaderTimer = window.setTimeout(() => {
                filterLoader.hidden = false;
            }, 140);
            hideLoaderTimer = window.setTimeout(hideFilterLoader, 15000);
        };

        filterForms.forEach((form) => {
            let searchTimer = null;
            const searchInputs = form.querySelectorAll('input[type="search"], input[data-auto-search]');

            form.addEventListener('formdata', (event) => {
                for (const [name, value] of [...event.formData.entries()]) {
                    if (value === '' && event.formData.getAll(name).every((entry) => entry === '')) {
                        event.formData.delete(name);
                    }
                }
            });

            searchInputs.forEach((input) => {
                input.addEventListener('input', () => {
                    window.clearTimeout(searchTimer);
                    searchTimer = window.setTimeout(() => {
                        const currentValue = new URL(window.location.href).searchParams.get(input.name) || '';
                        if (input.value === currentValue) return;

                        if (document.activeElement === input) {
                            sessionStorage.setItem(searchFocusStorageKey, JSON.stringify({
                                path: window.location.pathname,
                                name: input.name,
                                id: input.id,
                                value: input.value,
                                selectionStart: input.selectionStart,
                                selectionEnd: input.selectionEnd,
                                selectionDirection: input.selectionDirection,
                                savedAt: Date.now(),
                            }));
                        }

                        form.requestSubmit();
                    }, 400);
                });
            });

            form.addEventListener('change', (event) => {
                if (event.target.matches('select, input[type="date"], input[type="number"]')) {
                    window.clearTimeout(searchTimer);
                    sessionStorage.removeItem(searchFocusStorageKey);
                    form.requestSubmit();
                }
            });

            form.addEventListener('submit', () => {
                window.clearTimeout(searchTimer);

                form.querySelectorAll('[data-auto-filter-query]').forEach((input) => input.remove());
                const representedNames = new Set(
                    [...form.elements]
                        .filter((element) => element.name && !element.disabled)
                        .map((element) => element.name),
                );
                const actionUrl = new URL(form.action || window.location.href, window.location.href);

                if (actionUrl.pathname === window.location.pathname) {
                    new URL(window.location.href).searchParams.forEach((value, name) => {
                        if (name === 'page' || representedNames.has(name)) return;
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = name;
                        hidden.value = value;
                        hidden.dataset.autoFilterQuery = 'true';
                        form.append(hidden);
                    });
                }

                showFilterLoader();
            });
        });

        document.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target instanceof Element
                ? event.target.closest('a[data-auto-filter-reset], .pagination a')
                : null;
            if (!link || link.target || link.hasAttribute('download')) return;
            const targetUrl = new URL(link.href, window.location.href);
            if (targetUrl.origin === window.location.origin) {
                sessionStorage.removeItem(searchFocusStorageKey);
                showFilterLoader();
            }
        });

        window.addEventListener('pageshow', hideFilterLoader);
    }

    document.querySelector('[data-print-invoice]')?.addEventListener('click', () => window.print());

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.parentElement.querySelector('input');
            const reveal = input.type === 'password';

            input.type = reveal ? 'text' : 'password';
            button.textContent = reveal ? 'Hide' : 'Show';
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', String(reveal));
        });
    });

    document.querySelectorAll('[data-loading-form]').forEach((form) => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('button[type="submit"]');

            if (!button || button.disabled) {
                return;
            }

            button.disabled = true;
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
            const label = button.querySelector('.button-label');
            if (label) {
                label.textContent = 'Please wait…';
            }
        });
    });

    document.querySelectorAll('[data-toast-close]').forEach((button) => {
        button.addEventListener('click', () => dismissToast(button.closest('[data-toast]')));
    });

    document.querySelectorAll('[data-toast][data-autodismiss="true"]').forEach((toast) => {
        window.setTimeout(() => dismissToast(toast), 6500);
    });

    const password = document.querySelector('#password[autocomplete="new-password"]');
    const strengthLabel = document.querySelector('[data-password-strength]');
    if (password && strengthLabel) {
        password.addEventListener('input', () => {
            const value = password.value;
            const checks = [
                value.length >= 12,
                /[a-z]/.test(value),
                /[A-Z]/.test(value),
                /\d/.test(value),
                /[^A-Za-z0-9]/.test(value),
            ];
            const score = checks.filter(Boolean).length;
            strengthLabel.textContent = value.length === 0
                ? ''
                : score >= 5 ? 'Password requirements met.'
                    : 'Add at least 12 characters, uppercase and lowercase letters, a number and a symbol.';
        });
    }

    const sidebar = document.querySelector('#admin-sidebar');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    if (sidebar && sidebarToggle) {
        const closeSidebar = () => {
            sidebar.classList.remove('is-open');
            sidebarToggle.setAttribute('aria-expanded', 'false');
            sidebarToggle.setAttribute('aria-label', 'Open navigation');
        };

        sidebarToggle.addEventListener('click', () => {
            const isOpen = sidebar.classList.toggle('is-open');
            sidebarToggle.setAttribute('aria-expanded', String(isOpen));
            sidebarToggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });
    }

    const dialog = document.querySelector('[data-confirm-dialog]');
    if (dialog) {
        let pendingForm = null;

        document.addEventListener('submit', (event) => {
            const form = event.target.closest('form[data-confirm]');
            if (!form || form.dataset.confirmed === 'true') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            pendingForm = form;
            dialog.querySelector('[data-confirm-message]').textContent = form.dataset.confirm;
            dialog.querySelector('#confirm-title').textContent = form.dataset.confirmTitle || 'Confirm action';
            dialog.querySelector('[data-confirm-accept]').textContent = form.dataset.confirmAction || 'Confirm';
            dialog.showModal();
        }, true);

        dialog.querySelector('[data-confirm-cancel]').addEventListener('click', () => {
            pendingForm = null;
            dialog.close();
        });

        dialog.querySelector('[data-confirm-accept]').addEventListener('click', () => {
            if (!pendingForm) {
                return;
            }

            const form = pendingForm;
            pendingForm = null;
            form.dataset.confirmed = 'true';
            dialog.close();
            form.requestSubmit();
        });

        dialog.addEventListener('close', () => {
            if (pendingForm) {
                pendingForm = null;
            }
        });
    }

    const notificationMenu = document.querySelector('[data-notification-menu]');
    if (notificationMenu) {
        const list = notificationMenu.querySelector('[data-notification-list]');
        const count = notificationMenu.querySelector('[data-notification-count]');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        const notificationIndexUrl = notificationMenu.querySelector('.notification-dropdown-heading a').href;
        const notificationFeedPath = new URL(notificationMenu.dataset.feedUrl, window.location.href).pathname;
        const pollingIntervalMs = 30000;
        const notificationDestination = (url) => {
            try {
                const destination = new URL(url || notificationIndexUrl, window.location.href);
                if (
                    destination.origin !== window.location.origin
                    || destination.pathname === notificationFeedPath
                ) {
                    return new URL(notificationIndexUrl, window.location.href);
                }

                return destination;
            } catch {
                return new URL(notificationIndexUrl, window.location.href);
            }
        };
        const showNotificationError = (message) => {
            let error = notificationMenu.querySelector('[data-notification-error]');
            if (!error) {
                error = document.createElement('p');
                error.className = 'notification-error';
                error.setAttribute('role', 'alert');
                error.dataset.notificationError = 'true';
                notificationMenu.querySelector('.notification-dropdown-heading').after(error);
            }
            error.textContent = message;
        };
        notificationMenu.addEventListener('toggle', () => {
            notificationMenu.querySelector('summary').setAttribute('aria-expanded', String(notificationMenu.open));
        });
        document.addEventListener('click', (event) => {
            if (!(event.target instanceof Node) || notificationMenu.contains(event.target)) return;
            notificationMenu.open = false;
        });
        document.addEventListener('scroll', () => {
            notificationMenu.open = false;
        }, true);

        const loadNotifications = async () => {
            if (document.hidden) return;
            try {
                const response = await fetch(notificationMenu.dataset.feedUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('Notification feed request failed.');
                const data = await response.json();
                notificationMenu.querySelector('[data-notification-error]')?.remove();
                count.textContent = data.unread_count > 99 ? '99+' : String(data.unread_count);
                count.hidden = data.unread_count === 0;
                list.replaceChildren();
                if (!data.notifications.length) {
                    const empty = document.createElement('p');
                    empty.className = 'notification-empty';
                    empty.textContent = 'You’re all caught up.';
                    list.append(empty);
                    return;
                }
                data.notifications.forEach((notification) => {
                    const item = document.createElement('a');
                    item.className = `notification-preview${notification.read ? '' : ' is-unread'}`;
                    item.href = notificationDestination(notification.url).href;
                    const title = document.createElement('strong');
                    title.textContent = notification.title;
                    const description = document.createElement('span');
                    description.textContent = notification.description;
                    const time = document.createElement('small');
                    time.textContent = notification.created_at || '';
                    item.append(title, description, time);
                    item.addEventListener('click', async (event) => {
                        event.preventDefault();
                        try {
                            const template = notificationMenu.dataset.readUrlTemplate;
                            const response = await fetch(template.replace('__ID__', encodeURIComponent(notification.id)), {
                                method: 'POST',
                                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                                credentials: 'same-origin',
                            });
                            if (!response.ok) throw new Error('Could not update notification.');
                            window.location.assign(notificationDestination(item.href).href);
                        } catch (error) {
                            showNotificationError('Could not update this notification. Please try again.');
                            console.error(error);
                        }
                    });
                    list.append(item);
                });
            } catch (error) {
                const message = document.createElement('p');
                message.className = 'notification-empty';
                message.setAttribute('role', 'status');
                message.textContent = 'Notifications are temporarily unavailable.';
                list.replaceChildren(message);
                console.error(error);
            }
        };

        notificationMenu.querySelector('[data-notification-read-all]').addEventListener('click', async () => {
            try {
                const response = await fetch(notificationMenu.dataset.readAllUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('Could not mark notifications as read.');
                await loadNotifications();
            } catch (error) {
                showNotificationError('Could not mark notifications as read. Please try again.');
                console.error(error);
            }
        });

        loadNotifications();
        window.setInterval(loadNotifications, pollingIntervalMs);
        document.addEventListener('visibilitychange', loadNotifications);
    }
});

function dismissToast(toast) {
    if (!toast || toast.classList.contains('is-closing')) {
        return;
    }

    toast.classList.add('is-closing');
    window.setTimeout(() => toast.remove(), 200);
}
