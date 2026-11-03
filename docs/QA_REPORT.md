# QA Report — 2026-11-03

Scope: the whole booking module (admin panel, public booking, customer panel, payments) on the local dev server (MySQL 8.4, PHP 8.5, Laravel 13), plus the automated suite.

## Summary

| Area | Result |
|---|---|
| Automated tests | **184 / 184 passed** (915 assertions) |
| PHP syntax (all package files) | clean |
| Code style (Pint) | clean (1 file auto-fixed) |
| Page crawl: 42 pages × phone 375 px and desktop 1366 px | 84 checks: all expected status codes, no JS errors, no horizontal overflow; slowest page 0.8 s |
| End-to-end browser scenario (book → find → print → admin pay → calendar → cancel) | 17 / 17 passed |
| XSS probes (`<img onerror>`, `<script>` in name/notes) | escaped on review, booking page, admin list/detail, calendar and e-mail |
| Accessibility scan (public pages) | labels, button names, alt text, `lang`, `h1`: no issues |
| Query count (N+1 guard, new permanent test) | within budget on 13 pages |
| Concurrency (10 parallel processes, MySQL) | exactly one booking wins |

## Findings

| # | Severity | Finding | Status |
|---|---|---|---|
| 1 | Medium | `symfony/mime` CVE-2026-45070 (e-mail header injection), reported by `composer audit` | **Open: needs approval** for `composer update symfony/mime` (patch release) |
| 2 | Medium | No security headers on booking pages (clickjacking on booking/payment pages; signed URLs could leak through Referer on old browsers) | **Fixed:** `abs.headers` middleware (X-Frame-Options, nosniff, Referrer-Policy same-origin, Permissions-Policy) plus a test |
| 3 | Low | Admin "New Booking" offered times that had already passed today | **Fixed** plus a test |
| 4 | Low (performance) | Permission checks repeated identical DB queries on every call | **Fixed in abs** (per-request cache): calendar feed 17 → 6 queries, admin pages −6 to −12. About 35 queries per admin page remain in metheme's sidebar/header (`User::hasPermission()` has no cache) |
| 5 | Low (metheme) | `backend/img/undraw_profile.svg` (default avatar in the metheme header) is missing from the package: a 404 on every admin page for users without a photo | **Open:** fix in metheme (ship the file or use a fallback) |
| 6 | Info | 19 notification e-mails waiting in the queue on the dev machine | Expected: the queue worker is not running; start `php artisan queue:work` |
| 7 | Info | "Find booking" needs the phone number used on the booking; bookings made without a phone cannot be found that way | By design (signed e-mail link still works) |

## Not covered (needs real environments)

- Live or sandbox runs of bKash, Nagad, SSLCommerz, Stripe and PayPal (only HTTP fakes so far)
- Real devices and Safari; e-mail rendering in real mail clients
- HTTPS-only behaviour (secure cookies, HSTS) — set on the production web server
- Load testing beyond the concurrency test

## How to repeat

```bash
cd app
php artisan test --compact                                   # all tests
ABS_QUERY_REPORT=1 php artisan test vendor/mestiaque/abs/tests/Feature/QueryBudgetTest.php   # query counts per page
composer audit
```
