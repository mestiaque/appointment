# Database Design

All tables use the `abs_` prefix, so they cannot collide with host-app tables such as `payments` or `invoices`. Users, roles and media come from metheme (`users`, `roles`, `role_user`, `role_permissions`, `me_media`).

Money is stored as unsigned integers in the currency's minor unit (`*_minor`), with no floats. Instants are stored as UTC `DATETIME` values (`config('app.timezone')` must be `UTC`). The IANA timezone is kept on the business and copied onto every appointment.

## Tables

| Table | Purpose | Key constraints and indexes |
|---|---|---|
| `abs_businesses` | Tenant: name, slug, timezone, currency, status. Soft-deleted. | `slug` unique; `status` index |
| `abs_business_members` | User ↔ business with role `owner`/`manager`/`receptionist`/`staff`, `is_active` | unique (`business_id`, `user_id`); (`user_id`, `is_active`) |
| `abs_branches` | Optional locations | (`business_id`, `is_active`) |
| `abs_booking_settings` | One row per business: approval mode, notice/horizon, slot interval, cancellation and reschedule policy, payment mode, hold minutes, tax (basis points), reminder lead | `business_id` unique |
| `abs_service_categories` | Grouping | (`business_id`, `is_active`) |
| `abs_services` | Duration, price, buffers, deposit (none/fixed/percent). Soft-deleted. | (`business_id`, `is_active`) |
| `abs_staff` | Bookable people, optional login `user_id`. Soft-deleted. | unique (`business_id`, `user_id`) |
| `abs_staff_services` | Staff ↔ service, optional price/duration override | unique (`staff_id`, `service_id`) |
| `abs_branch_staff` | Staff ↔ branch (none = every branch) | PK (`branch_id`, `staff_id`) |
| `abs_staff_schedules` | Weekly local intervals (`weekday` 0 = Sunday; end may be `24:00`) | (`staff_id`, `weekday`) |
| `abs_staff_breaks` | Weekly local breaks | (`staff_id`, `weekday`) |
| `abs_staff_time_off` | UTC leave periods | (`staff_id`, `starts_at`, `ends_at`) |
| `abs_business_holidays` | Closed local dates, optionally per branch | (`business_id`, `date`) |
| `abs_appointments` | The booking: UTC `starts_at`/`ends_at`, plus `blocked_starts_at`/`blocked_ends_at` (with buffers), status, payment status, price totals, booking-time `tax_rate_bps`, customer contact, hold expiry | `booking_reference` unique; (`staff_id`, `blocked_starts_at`, `blocked_ends_at`) for conflicts; (`business_id`, `starts_at`); (`business_id`, `status`); (`status`, `hold_expires_at`); (`status`, `starts_at`, `reminder_sent_at`) for reminders; `customer_email` |
| `abs_appointment_items` | Immutable snapshot: service name, duration, buffers, unit price, quantity | FK cascade with the appointment |
| `abs_appointment_status_histories` | Every status change: from, to, actor, actor type, reason | (`appointment_id`, `created_at`) |
| `abs_appointment_reschedule_histories` | Old and new time/staff, actor, reason | — |
| `abs_sequences` | Per-business counters (invoice numbers) | unique (`business_id`, `name`) |
| `abs_payments` | One charge attempt: provider, purpose (full/deposit/balance), amount, status, provider references, refunded amount | unique (`provider`, `provider_reference`); (`business_id`, `status`, `paid_at`); (`appointment_id`, `status`) |
| `abs_payment_transactions` | Append-only payment event log (no card data) | (`payment_id`, `created_at`) |
| `abs_refunds` | requested → pending → completed/failed | (`business_id`, `status`, `processed_at`); `provider_reference` |
| `abs_invoices` | One per appointment, number per business | `appointment_id` unique; unique (`business_id`, `number`) |
| `abs_webhook_events` | Processed provider events (hash only, no payload) | unique (`provider`, `event_id`) |
| `abs_notification_logs` | Delivery log and dedupe key (masked recipient) | `dedupe_key` unique |

## Deletion rules

- Appointments, payments, refunds and invoices use `restrictOnDelete` towards businesses and appointments, so financial history cannot be removed by a cascade. Businesses, services and staff are soft-deleted.
- Deleting a branch sets `branch_id = null` on appointments. Deleting a category sets `category_id = null` on services.
- Catalog children (schedules, breaks, time off, pivots) cascade with their staff or service row.

## Not created (no implemented feature uses them)

Migration `2026_10_26_000001_add_tax_rate_to_abs_appointments` is additive and back-fills existing rows. It was verified on a database that already had data.

`appointment_holds` (holds are `awaiting_payment` appointments), `coupons`, `reviews`, `audit_logs` (the metheme Activity Log is used through `me_change_log()`), and `business_settings` (profile fields live on `abs_businesses`, booking rules in `abs_booking_settings`).
