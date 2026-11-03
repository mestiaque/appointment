# Testing

Tests live in `abs/tests` and run from the host app (testsuite `Abs` in `phpunit.xml`).

```bash
cd app
php artisan test --compact vendor/mestiaque/abs/tests                 # booking module (141 tests)
php artisan test --compact vendor/mestiaque/abs/tests/Feature/ConcurrentBookingTest.php
php artisan test --compact                                            # whole app
```

The test database is MySQL `abs_testing` (`phpunit.xml`). The development PHP has no `pdo_sqlite`, and the concurrency tests need real row locks. The database is wiped by `RefreshDatabase`/`DatabaseMigrations`, so never point it at real data.

| File | Covers |
|---|---|
| Unit/IntervalTest, CurrencyTest, LocalTimeTest, AppointmentStatusTest | overlap math, minor units and rounding, DST gap/overlap, transition map, role ceilings |
| Feature/SlotGenerationTest | working hours, multi-interval, breaks, time off, holidays, buffers, blocking statuses, holds, notice and horizon, business timezone, DST day, midnight/year crossing, staff overrides |
| Feature/BookingActionTest | instant/approval, snapshot immutability, double booking, any-staff, foreign service/staff/branch ids, inactive records, guest toggle, holds |
| Feature/LifecycleTest | transitions and history, mass-assignment protection, approval/rejection, cancellation policy, reschedule (success, conflict, self-overlap, other staff, policy) |
| Feature/ConcurrentBookingTest | 10 parallel OS processes on MySQL; any-staff distribution; overlapping different starts |
| Feature/AdminPagesTest | every admin page renders inside `me::master`; calendar feed; business creation |
| Feature/AuthorizationTest | IDOR on every resource, session tampering, payload tampering, permission vs membership, staff "own only", super admin, Livewire authorization and locked properties, upload validation, owner safeguards |
| Feature/PaymentTest | cash, Stripe checkout and webhooks (valid, invalid, replayed), duplicates, amount mismatch, out-of-order, deposit, late payment refund, partial refunds, refund webhooks, refund permission, reject → refund, hold expiry |
| Feature/NotificationTest | mail rendering, dedupe on retry, failure logging, after-commit only, reminders window and dedupe |
| Feature/PublicBookingTest | wizard end to end (guest and registered), tampered choices, re-check on submit, signed links, customer cancel/reschedule, rate limit |
| Feature/CatalogTest | service money parsing (JPY), staff overrides and branches, schedule editor validation, time off timezone, settings, approval re-check |
| Feature/ReportTest | gross, collected, refunds and outstanding separation, business scoping, CSV formula injection |

| Feature/PayPalTest | order creation, server-side capture on return, amount mismatch, verified/unverified webhooks, no capture after cancellation, refunds |
| Feature/DiscountTest | re-pricing with the snapshotted tax, limits, permission, customer history scoping |
| Feature/ManualBookingTest | empty-state reasons, next free day, clear validation message |

Responsive check (manual, 2026-10-25): headless Chrome screenshots at 375 px and 1280 px of the booking wizard (every step) and the dashboard, appointment list and detail, calendar, new booking, schedule, reports and settings pages. There was no horizontal page overflow; wide tables scroll inside their card. This is not automated.

Not automated: live Stripe and PayPal.
