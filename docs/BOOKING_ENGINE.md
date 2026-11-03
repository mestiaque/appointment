# Booking Engine

## Availability rules

A candidate start `S` for a service of `D` minutes with buffers `Bb`/`Ba` (staff override applied):

- service interval = `[S, S + D)`
- blocked interval = `[S − Bb, S + D + Ba)`

The candidate is available when all of these hold:

1. The business is active, the branch (if any) is active and belongs to it, the service is active and belongs to it, and the staff member is active, accepts bookings, offers the service and works at the branch (no branch assignment means every branch).
2. The service interval is fully covered by the staff's working intervals. Intervals that touch are joined, including across days, so `22:00–24:00` plus the next day's `00:00–02:00` allows bookings over midnight.
3. The service interval does not overlap a weekly break, a time-off period, or any business holiday date (the whole local day; branch-specific holidays apply only to that branch).
4. The blocked interval does not overlap the blocked interval of a blocking appointment of the same staff. Overlap means `a.start < b.end AND a.end > b.start`, so intervals that only touch do not overlap.
5. Online customers only: `now + min_notice ≤ S ≤ now + max_days_ahead`. Staff bookings skip this rule.

Buffers protect neighbouring appointments. They may extend into breaks or past closing time.

## Blocking statuses

| Status | Blocks its slot |
|---|---|
| confirmed, checked_in, in_progress, completed, no_show | always |
| pending | when `pending_blocks_slot` is on (default) |
| awaiting_payment | until `hold_expires_at` |
| rejected, cancelled, expired | never |

A pending request is never released just because it is old. When pending requests do not hold their slot, approving one re-checks the slot under lock, so a clashing request cannot be approved. A payment that arrives after its hold expired does the same check; if the slot is gone, the booking expires and the payment is refunded automatically.

## Concurrency

The availability shown in the UI is advisory. `AppointmentBookingAction` runs one transaction:

1. `SELECT … FROM abs_staff WHERE id = ? FOR UPDATE` (a stable row per exclusive resource).
2. Read the conflicting appointments with `FOR UPDATE`. Locking reads always see the latest committed rows, even under REPEATABLE READ.
3. Insert the appointment.

A competing request for the same staff waits at step 1 and then sees the committed booking. For "any staff", candidates are tried in ascending id order, so two transactions never lock staff in opposite orders. Reschedules lock both staff rows in id order. Status changes lock staff, then the appointment, which is the same order. Deadlocks and lock timeouts are retried up to `abs.transaction_attempts` (3) by `DB::transaction`.

Verified by `tests/Feature/ConcurrentBookingTest.php`: 10 OS processes against MySQL 8.4 with a 150 ms delay injected inside the critical section. Exactly one booking succeeds. With the locks removed, all 10 succeeded, which shows the test can detect the race.

Limitations: the lock covers one database server. Behaviour on Galera or other multi-primary clusters is not verified. SQLite is not supported for concurrent production use.

## Lifecycle

```
awaiting_payment → pending | confirmed | cancelled | expired
pending          → confirmed | rejected | cancelled
confirmed        → checked_in | in_progress | cancelled | no_show
checked_in       → in_progress | no_show
in_progress      → completed
completed, rejected, cancelled, no_show, expired: final
```

Visit statuses follow the clock: check-in and start are allowed from the appointment's local day; completed and no-show only after the start time. Buttons that are not allowed yet are hidden.

Only `AppointmentStateMachine` changes `status`. It is not mass-assignable. Each change writes `abs_appointment_status_histories` (actor user, actor type, reason).

## Rescheduling

The action validates the policy (customers: setting plus notice; staff: permission). The duration and buffers come from the item snapshot. In one transaction it locks the old and new staff in id order, locks the appointment, checks the new interval while ignoring the appointment itself, writes the history, and updates the same row. The old time is held until the new time is secured. Notifications are sent after commit. `reminder_sent_at` is reset.

## Pricing

`subtotal = unit price × quantity` (quantity is 1 in the current UI). `tax = round_half_up(subtotal − discount) × tax_rate_bps / 10000` (0 = no tax). `total = subtotal − discount + tax`. Deposit is fixed (capped at the total) or a whole-number percent of the total. All values are computed on the server and snapshotted on the appointment and its item. The booking-time tax rate is stored on the appointment (`tax_rate_bps`).

Staff with `abs_appointment.edit` can apply a discount on a booking. It re-prices on the server with the stored tax rate. It may not exceed the subtotal, and it may not push the total below what has already been collected (refund first). An issued invoice is updated. Coupon codes for customers are not implemented.

## Timezones

Instants are stored in UTC. Each business has an IANA timezone, which is copied to its appointments. Schedules are local wall-clock times, converted per local date with `LocalTime::toUtc()`:

- A nonexistent local time (DST gap) returns `null` and is never offered.
- An ambiguous local time (DST overlap) resolves to the earlier occurrence.
- Days are 23, 24 or 25 hours long according to the zone.
- The server timezone is never used for business logic. `app.timezone` must be UTC.

## Booking references

`BK-` followed by 8 random characters from the Crockford alphabet (about 40 bits). They are non-sequential, unique by index, and regenerated on collision. Guests manage bookings through a Laravel signed URL (HMAC with `APP_KEY`, no expiry). Rotating `APP_KEY` invalidates all such links.
