# Design Tokens

The admin UI inherits the **metheme** theme (AdminLTE 4 + Bootstrap 5.3.7 + `encodex.css`). The booking module does not define a second theme. It reuses metheme classes and adds only the tokens below. Public pages use plain Bootstrap 5.3.7 with a few tokens of their own.

## Reused from metheme (do not redefine)

| Purpose | Class / token |
|---|---|
| Page shell | `@extends('me::master')`, `@section('title')`, `@push('buttons')`, `@push('css')`, `@push('scripts')` |
| Cards | `card glass-card`, `card shadow` |
| Tables | `table table-sm table-bordered table-hover table-striped table-encodex` |
| Buttons | `btn-encodex-create`, `-list`, `-edit`, `-show`, `-delete`, `-search`, `-clear`; `btn-savex` |
| Badges | soft only: `badge bg-{color}-subtle text-{color}` |
| Sidebar icon colours | `icc-38`, `icc-40`, `icc-55`, `icc-67`, `icc-81` |
| Toasts | `session('success')` / `session('error')` → toastr |
| Fonts | Source Sans 3 (Latin), Hind Siliguri / Noto Sans Bengali (Bangla) |

## Status colours (Bootstrap names, from the enums)

| Status | Badge colour | Calendar hex |
|---|---|---|
| awaiting_payment | warning | `#ffc107` |
| pending | warning | `#fd7e14` |
| confirmed | primary | `#0d6efd` |
| checked_in | info | `#0dcaf0` |
| in_progress | info | `#6f42c1` (ring) |
| completed | success | `#198754` |
| cancelled / rejected | danger | faded, struck through |
| no_show | danger | faded |
| expired | secondary | hidden on the calendar |

Payment status: unpaid `secondary`, partially paid `warning`, paid `success`, refunded `info`.

## Staff colour palette (calendar)

Assigned by position in the business's staff list (`CalendarController::PALETTE`), and stable across pages:

`#2563eb` `#db2777` `#059669` `#d97706` `#7c3aed` `#0891b2` `#dc2626` `#65a30d` `#4f46e5` `#c2410c`

Event background = staff colour at 14 % alpha; a 3 px inset bar uses the full colour; text is `#111827`.

## Calendar tokens (`resources/views/admin/appointments/calendar.blade.php`)

| Token | Value |
|---|---|
| `--fc-border-color` | `#edf0f5` |
| `--fc-non-business-color` | `rgba(241,245,249,.75)` |
| `--fc-now-indicator-color` | `#ef4444` |
| Accent (today, active button) | `#2563eb` |
| Slot height (30 min) | `3rem` |
| Event radius | `.45rem` |
| Card radius (stats, scrollgrid) | `.75rem` |
| Chip radius | `999px` |
| Muted text | `#6b7280`, labels `#9ca3af` |

## Public booking pages (`abs::layouts.public`)

| Token | Value |
|---|---|
| `--abs-accent` | `#0d6efd` |
| Page background | `#f4f6fb` |
| Card | `border-radius: 1rem; box-shadow: 0 .5rem 1.5rem rgba(20,30,60,.07)` |
| Choice tile | `border: 1px solid #e3e7ef; radius .75rem`; active `#f0f6ff` |
| Max content width | `960px` |

## Spacing, type and breakpoints

- Bootstrap spacing scale (`.25rem` steps). Admin text is mostly `.78–.85rem`; titles `1.15–1.25rem`, weight 600–700.
- Breakpoints: Bootstrap defaults. `< 768px` → calendar list view, wrapped toolbars, horizontally scrolling chips and stats; `< 576px` → narrower bar selects.
- Minimum touch target on public pages: Bootstrap default button height (~38 px).

## Email

Booking mails extend `me::mail.master` (table layout, inline styles) and use `me::mail.partials.button`.
