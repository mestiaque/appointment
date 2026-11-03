# Release Checklist

## Before packaging

- [ ] `php artisan test --compact vendor/mestiaque/abs/tests` passes on MySQL
- [ ] `vendor/bin/pint ../abs/src ../abs/tests` clean
- [ ] `composer audit` clean
- [ ] Live Stripe test-mode run: checkout, webhook, partial refund
- [ ] PayPal sandbox run: approve, capture, webhook, refund
- [x] Headless Chrome pass at 375 px and 1280 px (2026-10-25); repeat on real devices before release
- [x] Fresh install on an empty database: `migrate` → `abs:install --demo`, re-run, rollback and re-migrate (2026-10-25)
- [ ] Upgrade from the previous release with existing data
- [ ] CHANGELOG updated, version tagged

## Package contents

- `abs/` package source (no `.env`, no `vendor/`, no `storage/` data)
- `docs/` (this folder)
- Do not ship metheme's seeded credentials or any real API keys. Demo passwords are generated randomly at seed time.

## Third-party inventory (runtime)

| Package | License | Use |
|---|---|---|
| laravel/framework 13 | MIT | framework |
| livewire/livewire 4.4 | MIT | interactive components |
| mestiaque/metheme | MIT | admin theme, auth, roles, mail/SMS, media |
| Bootstrap 5.3.7 (CDN) | MIT | public pages and invoice |
| Font Awesome Free (metheme asset) | Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT | icons |
| FullCalendar 6.1.15 (CDN, calendar page only) | MIT | calendar |
| Stripe API (no SDK) | service terms | optional online payments |
| PayPal REST API (no SDK) | service terms | optional online payments |

Run `composer licenses --no-dev` for the full PHP dependency list. All entries are MIT, BSD-3-Clause or Apache-2.0; `nette/*` is dual-licensed and BSD-3-Clause is used.
