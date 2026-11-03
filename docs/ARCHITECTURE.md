# Architecture

```
abs/src
├── AbsServiceProvider.php   config/permission/sidebar merge, middleware aliases, Livewire, scheduler, rate limiters
├── Config/                  config.php (abs.*), permission.php (metheme format), sidebar.php
├── Enums/                   AppointmentStatus (+ transition map), PaymentStatus, BusinessRole (+ ceilings) …
├── Support/                 Currency (minor units), Interval [start,end), LocalTime (DST policy),
│                            BusinessAccess, CurrentBusiness, CsvExporter
├── Services/Booking/        AvailabilityService, SlotGenerator, SlotFinder, BookingEligibilityService,
│                            PriceCalculator, BookingWindow, BookingReferenceGenerator, DTOs
├── Actions/                 AppointmentBookingAction, AppointmentReschedulingAction,
│                            AppointmentCancellationAction, AppointmentStateMachine
├── Payments/                PaymentGateway contract, GatewayManager, Gateways/StripeGateway, DTOs
├── Services/Payments/       PaymentLedger, CheckoutService, RefundService, InvoiceService, WebhookProcessor
├── Services/Notifications/  AppointmentNotifier (after-commit dispatch)
├── Notifications/           AppointmentNotification (queued, deduped), MeSmsChannel (optional)
├── Services/Reports/        ReportService
├── Console/                 abs:install, abs:send-reminders, abs:expire-holds
├── Http/Controllers/Admin   thin controllers (validate → load through business → call service/action)
├── Http/Controllers/Front   public booking, signed manage page, webhooks
├── Livewire/                BookingWizard, AppointmentTable, ManualBooking, ScheduleEditor, RescheduleForm
└── resources/views          admin (extends me::master), public (abs::layouts.public), mail (extends me::mail.master)
```

## Request flow (admin)

`web` → `auth` → metheme `LocaleMiddleware` and `activityLog` → `abs.business` (resolve and validate the current business from the session; share `$currentBusiness`) → `abs.can:<permission>` → controller. The controller loads every record through `$this->business()->relation()->findOrFail($id)`.

## Request flow (booking)

Livewire wizard (advisory slots from `SlotFinder`) → `AppointmentBookingAction` → transaction: lock staff row → re-check availability with locking reads → insert appointment, item snapshot and history → commit → `AppointmentNotifier` queues mails → if online payment is required, `CheckoutService` redirects to Stripe → verified webhook → `PaymentLedger` → `AppointmentStateMachine`.

## Integration with the host app

- No files of metheme or of the host app are modified by the package. The host app only gains `livewire/livewire` and `mestiaque/abs` in `composer.json`, an `Abs` test suite in `phpunit.xml`, and `METHEME_HOME_ROUTE` in `.env`.
- Routes are loaded in `booted()` because they call metheme's `me_prefix()`, which metheme defines in its own `boot()`.
- The admin home (`me_settings.home_route`) defaults to `abs.dashboard` when the app does not set one.
- Public pages use their own small layout. Metheme's guest layouts are marketing templates, and the admin theme is not changed.
