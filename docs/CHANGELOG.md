# Changelog

## 1.0.0-rc5 — 2026-11-03

- bKash, Nagad and SSLCommerz gateways; credentials per business from the admin panel (encrypted, write-only secrets, test connection)
- Customers choose the payment method; tokenized return URL for all providers (also fixes PayPal return URLs with extra query parameters)
- Refund "already refunded from the merchant portal" option
- Find my booking (booking number + phone, optional SMS code), private prescription/report documents, printable booking slip

## 1.0.0-rc4 — 2026-11-03

- Customer panel (`/account`): own login and registration, dashboard with next-appointment countdown, history, book again, profile and password
- Customers are kept out of the admin panel (`KeepCustomersOutOfAdmin`, `customer` role)
- Public and customer pages use a liquid-glass theme matching metheme

## 1.0.0-rc2 — 2026-10-25

- PayPal Checkout gateway (server-side capture, verified webhooks, refunds)
- Staff discount on a booking (server re-pricing with the booking-time tax rate; new column `tax_rate_bps`)
- Customer's other bookings on the appointment page
- Visit statuses respect time (no "completed"/"no-show" before the start)
- New Booking explains empty time lists and links the next free day
- Responsive schedule editor
- Site home `/` shows the public booking directory
- Clinic demo data (`abs:install --clinic-demo`, `AbsClinicDemoSeeder`)
- Redesigned calendar: colour per staff member, working hours shaded, closed weekdays hidden, correct business time zone,
  quick-action side panel, drag-and-drop reschedule (server-validated), click an empty slot to book, today's queue, period stats
- Business switcher is a native select (metheme's title bar clipped the dropdown)
- `CurrentBusiness` re-resolves when the user or session changes (safe for long-running processes)

## 1.0.0-rc1 — 2026-10-25

First release candidate of the Appointment Booking module (`mestiaque/abs`) for metheme.

- Multi-business setup with memberships (owner/manager/receptionist/staff), branches, booking settings
- Services, categories, deposits, buffers; staff with service price/duration overrides and branch assignment
- Weekly multi-interval schedules, breaks, time off, holidays; timezone- and DST-aware slot engine
- Public booking wizard (guest and registered), signed manage links, customer cancel/reschedule
- Admin: dashboard, appointment list and filters, calendar (FullCalendar), manual booking, lifecycle actions, history, internal notes
- Payments: cash/manual, Stripe Checkout, deposits, refunds (partial), webhooks with signature and idempotency, invoices/receipts
- Notifications: queued mail (metheme mail layout), optional SMS through metheme, reminders, dedupe log
- Reports with date ranges, service and staff breakdowns, CSV exports; diagnostics page; `abs:install`
- MySQL row-lock concurrency control, verified with multi-process tests
