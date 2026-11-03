# Deployment, Operations, Upgrade, Backup

## Production checklist

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `app.timezone=UTC`
- `METHEME_DEVELOPER_MODE=false`. Metheme's seeded default account changed or removed.
- `php artisan config:cache && php artisan route:cache && php artisan view:cache`
- Queue: `QUEUE_CONNECTION=database` (or redis), with a worker running (below).
- Scheduler cron is installed (below).
- Mail is configured on metheme's Mail Configuration page.
- If Stripe is used: live keys, the webhook endpoint registered, and one real payment and refund tested.
- Booking → Diagnostics shows no FAIL.
- `storage/` and `bootstrap/cache` are writable by the web user. The web root points to `public/` only.

## Queue worker (systemd example)

```ini
[Service]
ExecStart=/usr/bin/php /var/www/app/artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
User=www-data
```

After a deploy or a mail settings change: `php artisan queue:restart`.

## Scheduler (cron)

```cron
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

Registered tasks: `abs:expire-holds` every minute, `abs:send-reminders` every 5 minutes. Both use `withoutOverlapping()->onOneServer()`, which needs a shared cache store when you run several servers. Metheme recommends adding `metheme:media-cleanup` daily.

## Upgrade

```bash
php artisan down
composer update mestiaque/abs
php artisan migrate            # additive migrations only; never migrate:fresh on production
php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

Read CHANGELOG.md before upgrading. Take a backup first.

## Backup and restore

- **Database**: everything important (bookings, payments, invoices, settings) is in MySQL. Example: `mysqldump --single-transaction --routines --triggers DB > backup.sql`, run daily and kept off-server.
- **Files**: `storage/app/public/media` (logos via metheme media) and `.env` (stored securely; it holds `APP_KEY`).
- **Restore**: import the SQL dump, restore the files and `.env`, run `php artisan migrate`, then `php artisan queue:restart`.
- Keep the same `APP_KEY`. Changing it invalidates every guest "manage booking" link and metheme's encrypted mail/SMS passwords.

## Operational security

- Give `abs_business.all`, `abs_payment.refund` and `abs_report.export` only to trusted roles.
- Logs never contain card data. Webhook payloads are not stored, only their hash. Notification logs keep masked recipients.
- Customer notes are free text, and the booking form asks customers not to enter sensitive personal data. The module makes no compliance claims (HIPAA, GDPR, PCI-DSS). Assess your own obligations.
- Restrict access to `storage/logs`. Do not expose `/storage` other than `storage/app/public`.

## Expected resources

This is not benchmarked. Slot generation runs about 6 queries per staff member per day shown, so a 7-day picker with 5 staff runs about 200 small indexed queries per page update. Keep the number of staff per service in the dozens, or add caching, before very high traffic.
