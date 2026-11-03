# Installation

## Server requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo`, `zip` (`intl` recommended)
- MySQL 8.0+ or MariaDB 10.6+ (tested: MySQL 8.4.11)
- Composer 2
- A cron entry for the Laravel scheduler, and a queue worker (supervisor or systemd)
- HTTPS in production (signed links and payment webhooks)

## Steps (on top of an app that already has metheme)

```bash
composer require mestiaque/abs           # also installs livewire/livewire ^4.4
php artisan vendor:publish --tag=metheme-assets   # if metheme assets are not published yet
php artisan abs:install                  # checks requirements, runs pending migrations, creates booking roles
php artisan abs:install --demo           # optional generic demo data (only when no business exists)
php artisan abs:install --clinic-demo    # or: doctor's chamber / clinic demo (Asia/Dhaka, BDT)
```

`abs:install` never runs `migrate:fresh` or deletes data. It is safe to run again.

## First configuration

1. **Change or delete metheme's default seeded account** (`admin@mestiaque.com`). Its password is public.
2. Keep `timezone` in `config/app.php` as `UTC` (the business timezone is set per business), and set `METHEME_DEVELOPER_MODE=false` on customer installations.
3. Users & Roles → Users: create staff users with the **Booking Owner**, **Booking Receptionist** or **Booking Staff** role.
4. Booking → Businesses → Add Business (timezone, currency).
5. Add services, then staff (assign services), then set working hours on each staff member's *Hours* page.
6. Share the booking page `/book/{business-slug}` (or `/book`, which is also the site home `/` in this app).
7. Mail: metheme's **Mail Configuration** page (SMTP). Until it is set, mail is written to the log.
8. Optional: online payments, see PAYMENT_INTEGRATION.md.

## Environment keys added by this module

| Key | Default | Meaning |
|---|---|---|
| `ABS_ADMIN_PATH` | `booking` | Admin URL segment (`/admin/booking`) |
| `ABS_PUBLIC_PATH` | `book` | Public URL segment |
| `ABS_ACCOUNT_PATH` | `account` | Customer panel (`/account`, `/account/login`, `/account/register`) |
| `ABS_DEFAULT_TIMEZONE`, `ABS_DEFAULT_CURRENCY` | `UTC`, `USD` | Defaults for a new business |
| `ABS_PAYMENT_GATEWAY` | empty | `stripe` or `paypal` to enable online payment |
| `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_API_BASE` | — | Stripe |
| `PAYPAL_CLIENT_ID`, `PAYPAL_SECRET`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_API_BASE` | — | PayPal |
| `ABS_SMS_NOTIFICATIONS` | `false` | Also send SMS through metheme's `me_sms()` |
| `ABS_RATE_LIMIT_BOOKING`, `ABS_RATE_LIMIT_SLOTS` | `10`, `60` | Per-IP limits per minute |
| `METHEME_HOME_ROUTE` | (unset → `abs.dashboard`) | Page shown after login |

No web installer is included. Metheme already owns authentication and first-run setup, and a second installer could re-run setup on a live site. `abs:install` plus the Diagnostics page (Booking Reports → Diagnostics) cover requirement checks.
