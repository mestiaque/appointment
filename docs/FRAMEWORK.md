# Framework & Technology

## Stack (installed versions)

| Layer | Technology | Version | Notes |
|---|---|---|---|
| Language | PHP | 8.5 (8.3+ supported) | strict types in signatures, enums, readonly DTOs |
| Framework | Laravel | 13.38 | |
| Admin theme / auth / roles | mestiaque/metheme | 1.x-dev | login, users, roles and permissions, mail/SMS settings, media, activity log |
| Interactive UI | Livewire | 4.4 | wizard, appointment table, manual booking, schedule editor, reschedule form |
| CSS | Bootstrap | 5.3.7 (CDN via metheme) | no Tailwind in the module |
| JS | jQuery (metheme), Alpine (bundled with Livewire), FullCalendar 6.1.15 + Luxon 3.5 (calendar page only) | | |
| Database | MySQL / MariaDB | MySQL 8.4 tested | row locks required for concurrency |
| Queue | Laravel queue (`database` default) | | mails, notifications |
| Scheduler | Laravel scheduler | | `abs:expire-holds` every minute, `abs:send-reminders` every 5 minutes |
| Tests | PHPUnit | 12.5 | run from the host app; testsuite `Abs` |
| Formatter | Laravel Pint | 1.27 | Laravel preset |
| Payments | Stripe REST, PayPal REST | no SDKs | through `PaymentGateway` |

## Package layout and boot

- Composer package `mestiaque/abs`, namespace `ME\Abs\`, auto-discovered provider `ME\Abs\AbsServiceProvider`.
- `register()`: merges `abs` config and `permissions`; binds `BusinessAccess`, `CurrentBusiness` and `AppointmentNotifier` as **scoped**, and `GatewayManager` as a singleton.
- `boot()`: views (`abs::`), anonymous components (`x-abs::…`), translations, migrations, sidebar merge, middleware aliases (`abs.business`, `abs.can`), Livewire components, rate limiters, commands, schedule. Routes are loaded in `booted()`, after metheme defines `me_prefix()`.

## Architectural rules (followed throughout)

1. **Thin controllers.** Validate, load through the current business, call an action or service, return.
2. **Domain logic in actions and services.** These include `AppointmentBookingAction`, `AppointmentReschedulingAction`, `AppointmentCancellationAction`, `AppointmentStateMachine`, `AppointmentDiscountAction`, `AvailabilityService`, `SlotGenerator`, `PaymentLedger`, `RefundService` and `CheckoutService`. Livewire components never contain the algorithm.
3. **One way to change state.** Status → state machine. Money → ledger and refund service.
4. **Business isolation.** Every query is filtered by the current business, with no global scopes.
5. **Side effects after commit.** Notifications use `DB::afterCommit`. Provider calls never run inside a DB transaction.
6. **Integer money, UTC time.** See BOOKING_ENGINE.md.
7. **Metheme conventions.** `me::master`, metheme permissions format, `me_change_log()` for audit, `HasMedia` for logos, `me_mail` layout.
8. **No new dependency without approval.** Livewire was approved. Stripe and PayPal use the HTTP client.

## Coding conventions

- PSR-12 via Pint. Explicit return types. Constructor property promotion. PHPDoc array shapes.
- Enum keys in TitleCase.
- Explicit `$fillable` on models. Status and payment fields are excluded from mass assignment.
- Tests: feature tests first; factories in `ME\Abs\Database\Factories`; MySQL test DB `abs_testing`.

## Extension points

| Want to | Do |
|---|---|
| Add a payment provider | Implement `PaymentGateway`, call `app(GatewayManager::class)->extend('name', Class::class)` |
| Change role ceilings | `ME\Abs\Enums\BusinessRole::allows()` |
| Add a notification channel | Add a channel to `AppointmentNotification::via()` (see `MeSmsChannel`) |
| Silence notifications during imports | `app(AppointmentNotifier::class)->muted(fn () => …)` |
| Change URLs | `ABS_ADMIN_PATH`, `ABS_PUBLIC_PATH` |
