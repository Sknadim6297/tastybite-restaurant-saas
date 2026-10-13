document.addEventListener('DOMContentLoaded', () => {
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
        const interval = Math.max(30000, Number(notificationMenu.dataset.pollingInterval || 60) * 1000);
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
                    item.href = notification.url || notificationMenu.querySelector('.notification-dropdown-heading a').href;
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
                            const destination = new URL(item.href, window.location.origin);
                            if (destination.origin === window.location.origin) window.location.assign(destination.href);
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
        window.setInterval(loadNotifications, interval);
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
