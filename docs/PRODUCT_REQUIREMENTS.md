# Product Requirements

Status column: **Done** = implemented and covered by automated tests; **Partial** = implemented, with a limit noted; **Not started**.

## 1. Product

A self-hosted, multi-business appointment booking module (`mestiaque/abs`) for Laravel 13 with the metheme admin panel. It is sold on CodeCanyon and is generic: it fits doctors' chambers, clinics, salons, consultants, repair shops, studios and training centres. No industry rule is hard-coded.

Out of scope: SaaS subscription billing, platform commissions, mandatory central-server or licence checks.

## 2. Users

| Persona | Needs |
|---|---|
| Customer / patient (guest or registered) | Find a time, book in under a minute, get confirmation, cancel or reschedule without calling |
| Receptionist / front desk | See today's queue, book walk-ins and phone calls, check patients in, take cash |
| Staff / doctor | See only their own appointments, mark visits in progress or completed |
| Business owner / manager | Configure services, staff, hours and policies; approve requests; see money and reports |
| Platform super admin | Access every business; diagnostics |

## 3. Functional requirements

| # | Requirement | Status |
|---|---|---|
| F1 | Several businesses per installation; users belong to businesses with a role (owner, manager, receptionist, staff) | Done |
| F2 | Business profile: name, logo, contact, timezone (IANA), currency, active/inactive | Done |
| F3 | Optional branches; staff can be limited to branches | Done |
| F4 | Service categories; services with duration, price, buffers before/after, deposit (none/fixed/percent) | Done |
| F5 | Staff profiles, optional login, services offered with price/duration override | Done |
| F6 | Weekly schedule with several intervals per day, breaks, time off, business/branch holidays | Done |
| F7 | Slot engine: working hours, breaks, leave, holidays, buffers, existing bookings, notice, horizon, DST-safe | Done |
| F8 | "Any available staff" option | Done |
| F9 | Instant confirmation or owner approval (per business) | Done |
| F10 | Public booking wizard (branch → service → staff → time → details → confirm), mobile friendly | Done |
| F11 | Guest booking and registered-customer booking ("My bookings") | Done |
| F12 | Guest manage link (signed URL): view, cancel, reschedule within policy | Done |
| F13 | Staff/manual booking from admin, prefilled from the calendar | Done |
| F14 | Appointment lifecycle: approve, reject, cancel, check in, start, complete, no-show, with history | Done |
| F15 | Reschedule with history (form and calendar drag-and-drop) | Done |
| F16 | Appointment list with search, filters and pagination; customer history | Done |
| F17 | Calendar: month, week, day and list views; staff colours; today's queue; quick actions | Done |
| F18 | Payments: cash/manual, online full payment, deposit, balance | Done |
| F19 | Gateways: Stripe Checkout and PayPal Orders behind one contract | Partial: not verified live |
| F20 | Refunds (partial/full), invoices/receipts, staff discount | Done |
| F21 | Notifications: received, confirmed, approved, rejected, cancelled, rescheduled, reminder, payment received/failed, refund | Done (mail); SMS optional through metheme |
| F22 | Reports: counts by status, gross booking value, collected, refunds, outstanding, by service and staff, CSV export | Done |
| F23 | Diagnostics page and `abs:install` | Done |
| F24 | Demo data (generic and clinic) | Done |
| F25 | Coupons, reviews, waiting lists, recurring appointments, multi-service carts | Not started |

## 4. Non-functional requirements

| Area | Requirement | How it is met |
|---|---|---|
| Correctness | No double booking, also under concurrency | Staff row lock plus re-check in one transaction; multi-process MySQL test |
| Time | Correct in every timezone and across DST | UTC storage, `LocalTime` policy, DST tests |
| Money | No float rounding, currencies with 0/2/3 decimals | Integer minor units, `Currency` helper |
| Security | Business data isolation, no IDOR, signed guest links, verified webhooks, CSV-injection safe, rate limits | See AUTHORIZATION_MATRIX.md, PAYMENT_INTEGRATION.md |
| Privacy | No medical data required; masked recipients in logs; no card data | Generic notes field; hosted checkout |
| UI | Uses the existing metheme admin theme; responsive at 375 px and up | Headless Chrome checks |
| Operability | Queue and scheduler; idempotent installer; diagnostics | DEPLOYMENT.md |
| Compatibility | PHP 8.3+, Laravel 13, Livewire 4, MySQL 8 / MariaDB 10.6+ | composer constraints |

## 5. Acceptance

The release-candidate criteria and their current evidence are in [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md) and [TESTING.md](TESTING.md). These remain open: live Stripe and PayPal runs, and checks on real devices.
