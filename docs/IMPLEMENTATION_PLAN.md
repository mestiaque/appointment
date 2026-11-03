# Implementation Plan

All booking code lives in the `mestiaque/abs` package (`abs/src`, namespace `ME\Abs`). The host app only requires the package and runs it. Metheme stays untouched.

## Architecture decisions

| Topic | Decision |
|---|---|
| Admin UI | Pages extend `me::master` and use metheme classes and components. Menus are added through `abs/src/Config/sidebar.php`. |
| Permissions | Declared in `abs/src/Config/permission.php` in metheme format, and checked with `hasPermission()`. |
| Business isolation | `business_members` (owner / manager / receptionist / staff) on top of the metheme permission. The current business lives in the session and is validated on every request (`CurrentBusiness`). Records are always loaded through the business relation, never through a global scope. |
| Super admin | Must hold the explicit permission `abs_business.all`. Metheme already grants every permission to super-admin roles and developers. |
| Money | Integer minor units (`*_minor` columns), plus a `Currency` helper that knows each currency's exponent (JPY 0, KWD 3, default 2). |
| Time | Every instant is stored in UTC. Each appointment and business keeps its IANA timezone. Schedules use local wall-clock times and are converted per date. |
| Holds | An online-payment booking is created with status `awaiting_payment` and `hold_expires_at`. It blocks the slot until it expires, and a scheduled job releases it. No separate `appointment_holds` table (it would duplicate the conflict check). |
| Concurrency | Inside the booking transaction, `SELECT … FOR UPDATE` locks the `staff` row, then the overlap check runs again and the insert follows. For "any staff", candidates are tried in ascending id order. |
| Payments | `PaymentGateway` contract. Implementations: `CashGateway`/manual recording, and `StripeGateway` (Checkout Sessions over the HTTP client, with verified webhooks). |
| Interactive UI | Livewire 4 for the public booking wizard, the appointment table, manual booking and the schedule editor. Plain controllers and forms for CRUD, as in metheme. |
| Admin audit | `me_change_log()` (metheme Activity Log) for catalog and settings changes. `appointment_status_histories` for the appointment lifecycle. |

## Phases

0. Audit → `PROJECT_AUDIT.md`, this file.
1. Foundation: package provider, config, permissions, sidebar, businesses, members, branches, booking settings, business context, base layout integration.
2. Catalog: service categories, services, staff, staff-service assignments, branch assignments, schedules and breaks, time off, holidays.
3. Engine: `Interval`, `LocalTimeConverter`, `SlotGenerator`, `AvailabilityService`, `BookingEligibilityService`, `PriceCalculator`, `BookingReferenceGenerator`, `AppointmentStateMachine`, `AppointmentBookingAction`, `AppointmentReschedulingAction`, `AppointmentCancellationAction`.
4. Appointments: public wizard, guest manage link, my bookings, admin list, calendar, manual booking, transitions, history.
5. Payments: contract, cash/manual, Stripe, webhooks, refunds, invoices.
6. Notifications: Laravel notifications (queued), notification log dedupe, reminders command plus scheduler.
7. Reports: dashboard, reports, CSV exports, diagnostics.
8. Hardening: tests (unit, feature, security, MySQL concurrency), docs, demo seeder, release checklist.
