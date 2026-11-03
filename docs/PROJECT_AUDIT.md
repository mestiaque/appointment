# Project Audit

Audit date: 2026-10-25. Scope: host app `app/` and the `mestiaque/abs` package (`abs/`), which holds all booking code.

## 1. Versions

| Component | Version | Source |
|---|---|---|
| PHP | 8.5.4 (CLI) | `php -v` |
| Laravel | 13.38.0 | `php artisan --version` |
| Livewire | 4.4.8 (added in this work, approved by the owner) | `composer show livewire/livewire` |
| Bootstrap | 5.3.7 JS (CDN) + AdminLTE 4 CSS | `metheme` `master.blade.php` |
| jQuery | bundled with metheme (`public/backend/vendor/jquery`) | metheme |
| Database | MySQL 8.4.11 | `php artisan db:show` |
| PHPUnit | ^12.5 | `composer.json` |
| Frontend build | Vite 8 + Tailwind 4 (Laravel skeleton default, not used by the admin theme) | `package.json` |

Missing PHP extension: `pdo_sqlite`. Tests therefore run on MySQL (`abs_testing`), which is also the engine needed for the concurrency tests.

## 2. Repository state found

- `app/` is a nearly empty Laravel 13 skeleton. All admin functionality comes from the `mestiaque/metheme` package (`vendor/mestiaque/metheme`, namespace `ME\`).
- `abs/` was an auto-generated package scaffold (`ME\Abs\AbsServiceProvider`, one `abs` table, a controller that returned a string). Its `permission.php` used a format that metheme cannot read (`'abs.view' => 'abs View'` rather than `module => [title, actions]`).
- `composer.lock` and `vendor/` still pointed at an old package `mestiaque/kazitds`, which no longer exists on disk, so the app could not boot. On the owner's instruction it was removed. Three tests in `tests/Feature/` that only covered kazitds (`CustomerMergeTest`, `CustomerTransactionHistoryTest`, `SaleCustomerMatchingTest`) were removed with it.
- `.env` pointed at the `kazitds` MySQL database, which belongs to other projects and has 802 tables. On the owner's instruction, a new empty `abs` database is used instead. Nothing in `kazitds` was touched.

## 3. Admin master layout (metheme)

- Layout: `@extends('me::master')`.
- Sections and stacks: `@section('title')` (also printed as the page heading), `@section('content')`, `@push('buttons')` (top-right of the heading bar), `@push('css')`/`@push('style')`, `@push('js')`/`@push('scripts')`.
- Assets: AdminLTE 4 CSS, `encodex.css`, `form-styles.css`, Font Awesome (local), Bootstrap Icons, Bootstrap 5.3.7 JS, jQuery, toastr, select2, Summernote, ApexCharts and SortableJS, all loaded by the layout. The layout already shows `session('success')`, `session('error')` and the first validation error through toastr.
- UI conventions: `card glass-card`, `table table-sm table-bordered table-hover table-striped table-encodex`, buttons `btn-encodex-create|list|edit|show|delete|search|clear`, `btn-savex`. Badges are always soft (`badge bg-X-subtle text-X`). Pagination uses `pagination::bootstrap-5`, and page size comes from `get_setting('pagination', 10)`.
- Reusable components: `me::components.btn.add-button`, `me::components.btn.save-button`, `me::components.media-input`, `me::roles.partials.badge`, `me::widgets.system-overview`, mail layout `me::mail.master` plus partials `button` and `otp`.
- Guest layouts (`guestMaster`, `guest`) are heavy marketing pages. The public booking pages therefore use their own small Bootstrap 5 layout (`abs::layouts.public`), and the admin theme is unchanged.

## 4. Navigation

- `config('sidebar')` is a numeric array. Each package merges its own items, which are sorted by `sl`. Item keys: `title`, `icon`, `route`, `for_active`, `permit`, `icon_color`, `children`, `header`, `params`.
- Groups with no visible child are hidden automatically. The header menu search uses the same rules.
- The abs provider already merges `Config/sidebar.php` with `array_merge`, which is the correct method for a numeric array.

## 5. Authentication and authorization

- Auth model: `ME\Models\User` (`config('auth.providers.users.model')`). `App\Models\User` exists but the guard does not use it.
- Login, registration and password reset come from metheme routes (`/login`, …). Admin routes use the prefix `me_prefix()` (default `admin`) and have unprefixed route names.
- Roles: `roles` (with `parent_id` hierarchy), `role_user`, and `role_permissions.permissions` (a JSON array of `module.action`).
- Check path: `User::hasPermission()` returns true for developers (developer mode plus a listed email) and for `super-admin` roles (`Roles::SUPER_ADMIN_SLUGS`). Otherwise it looks in the role permission JSON. `Gate::before` maps `can()` to `hasPermission()`. Middleware alias `authorization:module.action`.
- A permission must be declared in `config('permissions')` (`module => ['title' => ..., 'actions' => 'a,b']`), or it cannot be granted from the Roles page.
- Change logging: `me_change_log()` writes before/after diffs into `user_activities` (Activity Log).
- Mail: `me_mail()` and Laravel mail. The mail transport is configured from the DB settings page. Without SMTP settings, mail goes to the log.
- Media: `ME\Traits\HasMedia` with the `me_media` table. New tables must not get image columns.

## 6. Reusable pieces adopted by abs

| Need | Reused |
|---|---|
| Layout, theme, toasts | `me::master` |
| Global permissions | metheme roles plus `config('permissions')` |
| Menu | `config('sidebar')` |
| Admin audit trail | `me_change_log()` / Activity Log |
| Business logo | `HasMedia` (`me_media`) |
| Mail layout | `me::mail.master` |
| Settings page size | `get_setting('pagination')` |

## 7. Dependency assessment

- Added: `livewire/livewire ^4.4` (owner approved). It is compatible with Laravel 13 and PHP 8.5. It auto-injects its assets, and the bundled Alpine.js does not conflict with the jQuery stack.
- Not added: `stripe/stripe-php`. The Stripe Checkout integration uses Laravel's HTTP client and Stripe's documented HMAC webhook signature, which avoids a new dependency.
- Not added: FullCalendar via npm. The calendar loads `fullcalendar@6.1.15` from jsdelivr, the CDN metheme already uses, and only on the calendar page.

## 8. Risks and assumptions

1. Metheme seeds a default account (`admin@mestiaque.com`) whose password is public. It must be changed on every install (see INSTALLATION.md).
2. Metheme permissions are global and role-based. Business isolation therefore needs a second layer, business membership, implemented in abs. It is a scope check on top of the metheme permission, not a replacement for it.
3. Metheme's own OTP registration and reset flows are documented by metheme as unsafe. Guest booking does not depend on them.
4. Metheme's Activity Log has no permission check. This is outside abs scope, documented only.
5. Concurrency safety is verified on MySQL 8.4 only.
6. Live Stripe payments are untested because no credentials are available.
