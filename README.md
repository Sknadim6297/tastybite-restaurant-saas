<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## TastyBite Super Admin

The Super Admin portal is available at `/super-admin/login`. Admin accounts are isolated from restaurant users and can only be created from the server CLI; there is no public registration route.

### Local setup

1. Apply the additive Super Admin tables: `php artisan migrate`.
2. Create the first account with `php artisan super-admin:create`. Enter a name and unique email, then enter and confirm a password of at least 12 characters containing uppercase and lowercase letters, a number, and a symbol. Password input is hidden and is never printed.
3. Disable access with `php artisan super-admin:disable admin@example.com` and confirm the prompt. Re-running account creation for the same email does not change its password or re-enable it.

For local development only, you may set `LOCAL_SUPER_ADMIN_PASSWORD` in your untracked `.env` file and run `php artisan db:seed --class=SuperAdminSeeder`. The seeder refuses non-local environments, requires the existing `super_admins` table, and does not change an existing account or password. Never commit the local password.

Set `APP_ENV=production`, `APP_DEBUG=false`, and use HTTPS with `SESSION_SECURE_COOKIE=true` for deployment. Configure a real Laravel SMTP/API mail transport before enabling password recovery in production. For Plesk SMTP on port 587, set `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtp`, `MAIL_REQUIRE_TLS=true`, `MAIL_HOST`, `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and a valid `MAIL_FROM_ADDRESS`. For SMTP failover, set `MAIL_MAILER=failover` and `MAIL_FAILOVER_MAILERS=smtp,smtp_backup`, then configure the `MAIL_BACKUP_*` values. Failover defaults to SMTP only; log and array transports are rejected for password reset delivery and must not be added to a production failover list. Feature tests use an in-memory transport and do not deliver email to a user.

### Restaurant owner onboarding

Only authenticated Super Admins can provision restaurants and their first owner. The owner account receives a random, undisclosed password; no administrator enters or emails a password. With a working configured mail transport, the owner receives Laravel's one-time, 60-minute password setup link and chooses a password through the existing Restaurant Admin reset page. The reset token is single-use.

The default `log` mailer and any failover configuration that can write to logs are refused for setup/reset messages. In that case the restaurant and owner are created, but the owner does not receive a link and cannot know the random password. Configure a verified SMTP/API transport, then use **Send reset link** from the restaurant detail page. Restaurant-owner forgot-password responses do not reveal whether an account exists by default; `OWNER_PASSWORD_RESET_EXPOSE_MISSING_ACCOUNTS=true` explicitly enables an account-not-found message and should only be enabled where that disclosure is acceptable. Password reset mail is sent synchronously; it does not require a queue worker, even when `QUEUE_CONNECTION=database`. The application reports acceptance only after the configured transport returns successfully; confirm delivery with the SMTP provider's message logs. Do not use a log mailer or copy reset tokens from logs as an onboarding workaround.

Super Admins can manage all restaurant-owner accounts at `/super-admin/restaurant-owners`. The existing `users` table supports multiple owner accounts per restaurant and enforces globally unique email addresses. New owners receive an undisclosed random password and a single-use setup link through the configured mail transport; the application reports when delivery is unavailable rather than exposing a credential. Owner role and restaurant assignment cannot be changed through profile editing. Successful owner sign-ins update `users.last_login_at` and append an owner activity audit entry without recording an IP address, password, or token.

No TastyBite logo image is present in this workspace, so the Super Admin header uses a text-based brand lockup. The existing POS pages and assets remain unchanged.

## Subscription management

Apply the additive subscription migration with `php artisan migrate`. It creates missing subscription tables or reuses existing tables only when their required columns match, and adds missing history indexes and foreign-key constraints after checking for orphaned references. The migration cannot be rolled back because subscription history must be retained. Super Admins can create, edit, activate, and deactivate INR subscription plans, then assign, renew, extend, suspend, reactivate, or cancel restaurant subscription periods. Plan deletion is intentionally unavailable; subscription history is retained as append-only events, and each subscription stores a snapshot of the plan terms that applied when it was assigned.

## Manual payments

Super Admins can record manual INR subscription payments at `/super-admin/payments`. New entries are pending and do not grant access. Approval creates an initial subscription period or renews the restaurant's existing plan through the subscription service; pending/rejected payments never alter subscription access. A renewal for a restaurant whose latest subscription is suspended or cancelled must be resolved through subscription management before approval. Payment proof is stored on Laravel's private local disk and is downloadable only through the authenticated Super Admin route.

Cash, bank transfer, UPI, and other manual methods are supported. Refund entries retain their amount, method, reference, date, reason, and administrator. Partial and full refunds are tracked without changing the subscription, and recording a manual refund does not verify that funds were returned. Financial records are retained; the payment-table migration is additive and intentionally irreversible. No payment gateway or automatic payment capture is enabled.

The application timezone in `config/app.php` is used to interpret start and expiry dates. Expiry dates are valid through the end of that local calendar date. Subscription validity is checked on each operational request, so expiry enforcement does not depend on a scheduled task. A restaurant must have an active restaurant account, an active user, and a current active/trial subscription to open the POS or receipt route. Restaurant login, dashboard, profile, subscription status, renewal instructions, and logout remain available when a subscription is expired or suspended.

This step does not integrate payment gateways or collect payments. Restaurant renewal instructions direct the owner to contact the platform administrator. There are no default plans or subscription records; Super Admins must create and assign them.

## Feature management

Super Admins manage centralized SaaS feature access at `/super-admin/features`. The additive feature migration creates the feature registry and per-plan enablement/limit mappings. Existing plans receive Point of Sale access to preserve the prior subscription behavior; newly created plans require explicit configuration. POS authorization checks the signed-in restaurant's current subscription, the global feature state, and the mapping for that plan on every request. Planned modules are marked not implemented and cannot be enabled. Limits are stored for future feature modules; no unsupported limit behavior is claimed for the current POS page. Feature access changes are written to the platform audit log.

## Demo restaurant menu

For a non-production environment, seed an existing restaurant explicitly with `php artisan menu:seed-demo <restaurant-id>`. The command never creates a restaurant, is disabled in production, and only inserts missing matching categories and products; existing menu data is left unchanged. It adds 10 categories and 50 products with locally stored JPEGs under that restaurant's public-storage directory. Run it again safely to add any missing records without creating duplicates. Photo source, creator, and license credits are included in `database/seeders/data/demo-menu-images/attributions.json`; all photos are from Wikimedia Commons or Openverse and carry CC0, CC BY, or CC BY-SA terms. Ensure Laravel's `public/storage` link is available so the menu and POS can serve the stored images.

## Support tickets

Super Admins manage tickets at `/super-admin/support-tickets`; authenticated restaurant owners use `/restaurant/support`. Tickets, messages, status transitions, and restaurant-facing in-app ticket notices are stored in dedicated tables. Owner queries and attachment downloads are scoped to the signed-in restaurant. Attachments accept JPG, PNG, or PDF up to 5 MB and are stored on the private `local` disk; they are only downloaded through authorized ticket routes. Public replies notify the restaurant and internal notes are visible only to Super Admins. No external email is sent.

## Invoices and billing receipts

An approved manual subscription payment creates one linked receipt atomically with the subscription update. The invoice table enforces one receipt per payment; rejected and pending payments do not create one. The current platform has no configured tax/legal invoice settings, so generated documents are explicitly non-tax payment receipts with zero tax and no legal tax-invoice claim. Refund entries are reflected as recorded refunds and net retained value; they do not prove an external refund transfer. Demo-origin payments are marked as demo and may be filtered separately. PDF downloads use the Laravel 12-compatible `barryvdh/laravel-dompdf` package; print and CSV export are available from the authenticated Super Admin module.

## Platform reports, notifications, activity logs, and settings

The authenticated Super Admin sidebar links to platform reports (`/super-admin/reports`), the in-app notification inbox (`/super-admin/notifications`), read-only administrative activity (`/super-admin/activity-logs`), and persisted platform settings (`/super-admin/settings`). Reports are database-backed and separate demo records (restaurant slugs prefixed `demo-tb-2026-` and payments marked `DEMO DATA ONLY`) from real collections. Revenue counts approved payments and subtracts recorded refunds; a refund record does not confirm an external transfer.

New platform events create idempotent in-app notifications for active Super Admin accounts. Subscription expiry reminders are configured under Settings and generated by `php artisan super-admin:notify-subscription-expiry`. The Laravel scheduler must run for automatic daily reminders: on local development use `php artisan schedule:work`, or configure Windows Task Scheduler to run `php artisan schedule:run` every minute. On production, configure the host's standard Laravel scheduler entry. In-app polling is limited to visible browser tabs.

Settings are stored in the additive platform-settings migration. Logo and favicon uploads are kept on the private local storage disk and are served only through authenticated Super Admin routes. Manual-payment settings can disable new manual entries or constrain available methods; approval and refund operations remain auditable. Password and profile changes are recorded in the append-only activity log, and password changes invalidate other sessions on their next protected request.

## Platform administration modules

Restaurant approvals are available at `/super-admin/restaurant-approvals`. Super Admin provisioning can leave a restaurant pending review; pending, information-requested, and rejected restaurants cannot access operational routes. Existing restaurant rows default to approved when the approval migration is applied. The current provisioning workflow collects no verification documents, and it has no public restaurant-registration route.

Announcements are managed at `/super-admin/announcements` and shown to owners at `/restaurant/announcements`. They support draft, publish, archive, all-active-owner or selected-restaurant audiences, expiry, and per-restaurant read state. Delivery is in-app only; publishing does not send email.

Platform analytics are available at `/super-admin/analytics` with date and real/demo/all scopes. Amounts are derived from approved manual payment and refund records and are grouped by currency without conversion. They describe recorded amounts, not independently verified money transfers. The existing detailed Reports module remains available separately.

The Security Center at `/super-admin/security-center` shows effective session and password-reset settings, account state, and recorded authentication events. Failed credential checks store a keyed account fingerprint rather than a submitted email or password; malformed and rate-limited requests are not included. System Health at `/super-admin/system-health` performs a read-only database ping and reports pending migration filenames and configured service drivers. It does not run migrations, probe external mail/cache/queue delivery, or verify scheduler execution.

Super Admins use **Restaurants → Go to Dashboard** for temporary direct access to eligible restaurant dashboards. The Super Admin identity remains active, tenant data stays scoped, access/actions are audited, and **Return to Super Admin** or logout clears the temporary session.

The former support-access request workflow has been retired. Its historical `support_access_requests` and `support_access_request_events` tables, records, and `support_access_request` audit entries are retained without request or approval routes. Review these structures separately before any future schema cleanup; this change does not migrate or delete their data. The additive schema for feature management, support tickets, invoices, restaurant approvals, announcements, and support-access history is provided by migrations `2026_10_13_000007` through `2026_10_13_000012`. PHPUnit forces an isolated in-memory SQLite database and does not modify development MySQL.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com/)**
- **[Tighten Co.](https://tighten.co)**
- **[WebReinvent](https://webreinvent.com/)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel/)**
- **[Cyber-Duck](https://cyber-duck.co.uk)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Jump24](https://jump24.co.uk)**
- **[Redberry](https://redberry.international/laravel/)**
- **[Active Logic](https://activelogic.com)**
- **[byte5](https://byte5.de)**
- **[OP.GG](https://op.gg)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
#   t a s t y b i t e - r e s t a u r a n t - s a a s 
 
 