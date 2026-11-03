# Authorization Matrix

An action is allowed only when both layers agree:

1. **Metheme permission.** The user's metheme role holds the permission (`User::hasPermission`). Super-admin roles and developer accounts hold every permission.
2. **Business scope.** The user is *privileged* (`abs_business.all`) or has an **active membership** in the current business whose role allows the permission.

Records are loaded through the current business, so an id from another business returns 404. No global Eloquent scopes are used.

## Business role ceilings (`ME\Abs\Enums\BusinessRole`)

| Permission | Owner | Manager | Receptionist | Staff |
|---|:-:|:-:|:-:|:-:|
| abs_business.view | ✓ | ✓ | ✓ | ✓ |
| abs_business.edit (profile, team members) | ✓ | ✓ | – | – |
| abs_business.delete (also: grant the owner role) | ✓ | – | – | – |
| abs_branch.*, abs_service.*, abs_staff.* (create/edit/delete) | ✓ | ✓ | view only | – |
| abs_schedule.view | ✓ | ✓ | ✓ | ✓ |
| abs_schedule.edit | ✓ | ✓ | – | – |
| abs_appointment.view | ✓ | ✓ | ✓ | own only |
| abs_appointment.create / edit (notes, discount) / approve / cancel / reschedule | ✓ | ✓ | ✓ | – |
| abs_appointment.status (check-in, start, complete, no-show) | ✓ | ✓ | ✓ | own only |
| abs_payment.view / record | ✓ | ✓ | ✓ | – |
| abs_payment.refund | ✓ | ✓ | – | – |
| abs_report.view | ✓ | ✓ | ✓ | – |
| abs_report.export | ✓ | ✓ | – | – |
| abs_setting.view / edit | ✓ | ✓ | – | – |

"Own only": a staff-role member sees appointments whose `staff_id` is the staff profile linked to their user. With no linked profile, they see nothing.

Not business-scoped: `abs_business.create` (create a new business; the creator becomes its owner), `abs_business.all` (privileged access to every business), and `abs_diagnostic.view`.

## Two interfaces

| Interface | URL | Who | Theme |
|---|---|---|---|
| Admin panel | `/{METHEME_ROUTE_PREFIX}` (default `/admin`) | users with an admin/booking role | metheme `me::master` |
| Customer panel | `/account`, `/book` | everyone; customers only here | liquid-glass `abs::layouts.public` |

A **customer** is a user whose only role is `customer`, or who has no role. Self-registration (`/account/register`) gives the `customer` role. `KeepCustomersOutOfAdmin` runs in the `web` group and sends customers from any admin URL to `/account`, including after metheme's own `/login`. Developers and users with any other role keep admin access, and may also open `/account`. Customer login is rate limited (5 per minute per e-mail and IP), and an admin "intended" URL is never used as the post-login target.

## Default metheme roles (`AbsRolesSeeder`)

| Role | Permissions |
|---|---|
| Booking Owner | every `abs_*` permission except `abs_business.all` and `abs_diagnostic.view` |
| Booking Receptionist | the receptionist column above |
| Booking Staff | business.view, schedule.view, appointment.view, appointment.status |
| Customer | none (customer panel only) |

## Where it is enforced

- Routes: `authorization:` (metheme) for non-business routes, and `abs.business` + `abs.can:` for business routes.
- Controllers: records are loaded through business relations, and appointment transitions check the permission that matches the target status.
- Livewire: every action and render calls `authorizeBusiness()`. `#[Locked]` ids cannot be changed by the client. The current business comes from the session, never from a public property.
- Validation: every foreign key in a payload is checked with `exists:...,business_id`.
- Public: signed URLs for manage, cancel, pay and payment-return; rate limits `abs-booking`, `abs-slots` and `abs-webhook`.

Tests: `tests/Feature/AuthorizationTest.php`, `PublicBookingTest.php`, `PaymentTest.php` (refund permission).
