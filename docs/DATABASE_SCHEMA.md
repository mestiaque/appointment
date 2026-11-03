# Database Schema

Column-level reference for every `abs_*` table. **Generated from the live MySQL 8.4 database on 2026-11-03** (`SHOW COLUMNS`), after all 5 migrations. Relationships, indexes and deletion rules are explained in [DATABASE_DESIGN.md](DATABASE_DESIGN.md).

Conventions:
- `*_minor` columns hold money as integers in the currency's minor unit (cents, paisa). There are no floats.
- `DATETIME` columns hold UTC instants. Local wall-clock values are only in `abs_staff_schedules`/`abs_staff_breaks` (`TIME`, business timezone) and `abs_business_holidays.date` (local date).
- Key: `PRI` = primary key, `UNI` = unique, `MUL` = indexed or foreign key.

Migrations:

| File | Adds |
|---|---|
| `2026_10_25_200001_create_abs_business_tables` | businesses, members, branches, booking settings |
| `2026_10_25_200002_create_abs_catalog_tables` | categories, services, staff, pivots, schedules, breaks, time off, holidays |
| `2026_10_25_200003_create_abs_appointment_tables` | appointments, items, status/reschedule history, sequences |
| `2026_10_25_200004_create_abs_payment_tables` | payments, transactions, refunds, invoices, webhook events, notification logs |
| `2026_10_26_000001_add_tax_rate_to_abs_appointments` | `abs_appointments.tax_rate_bps` (with back-fill) |


### `abs_appointment_items`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| appointment_id | bigint unsigned | no | | MUL |
| service_id | bigint unsigned | yes | | MUL |
| service_name | varchar(255) | no | | |
| duration_minutes | smallint unsigned | no | | |
| buffer_before_minutes | smallint unsigned | no | 0 | |
| buffer_after_minutes | smallint unsigned | no | 0 | |
| unit_price_minor | bigint unsigned | no | | |
| quantity | smallint unsigned | no | 1 | |
| line_total_minor | bigint unsigned | no | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_appointment_reschedule_histories`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| appointment_id | bigint unsigned | no | | MUL |
| old_starts_at | datetime | no | | |
| old_ends_at | datetime | no | | |
| old_staff_id | bigint unsigned | yes | | MUL |
| new_starts_at | datetime | no | | |
| new_ends_at | datetime | no | | |
| new_staff_id | bigint unsigned | yes | | MUL |
| actor_user_id | bigint unsigned | yes | | MUL |
| actor_type | varchar(20) | no | | |
| reason | varchar(255) | yes | | |
| created_at | timestamp | no | CURRENT_TIMESTAMP | |

### `abs_appointment_status_histories`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| appointment_id | bigint unsigned | no | | MUL |
| from_status | varchar(20) | yes | | |
| to_status | varchar(20) | no | | |
| actor_user_id | bigint unsigned | yes | | MUL |
| actor_type | varchar(20) | no | | |
| reason | varchar(255) | yes | | |
| created_at | timestamp | no | CURRENT_TIMESTAMP | |

### `abs_appointments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| branch_id | bigint unsigned | yes | | MUL |
| staff_id | bigint unsigned | no | | MUL |
| service_id | bigint unsigned | yes | | MUL |
| customer_user_id | bigint unsigned | yes | | MUL |
| booking_reference | varchar(20) | no | | UNI |
| customer_name | varchar(255) | no | | |
| customer_email | varchar(255) | no | | MUL |
| customer_phone | varchar(40) | yes | | |
| starts_at | datetime | no | | |
| ends_at | datetime | no | | |
| blocked_starts_at | datetime | no | | |
| blocked_ends_at | datetime | no | | |
| timezone | varchar(64) | no | | |
| status | varchar(20) | no | | MUL |
| approval_mode | varchar(20) | no | | |
| payment_status | varchar(20) | no | unpaid | |
| payment_method | varchar(20) | no | pay_later | |
| currency | char(3) | no | | |
| subtotal_minor | bigint unsigned | no | | |
| discount_minor | bigint unsigned | no | 0 | |
| tax_minor | bigint unsigned | no | 0 | |
| tax_rate_bps | int unsigned | no | 0 | |
| total_minor | bigint unsigned | no | | |
| deposit_minor | bigint unsigned | no | 0 | |
| paid_minor | bigint unsigned | no | 0 | |
| refunded_minor | bigint unsigned | no | 0 | |
| customer_notes | text | yes | | |
| internal_notes | text | yes | | |
| cancellation_reason | varchar(255) | yes | | |
| source | varchar(20) | no | online | |
| created_by_user_id | bigint unsigned | yes | | MUL |
| hold_expires_at | datetime | yes | | |
| confirmed_at | datetime | yes | | |
| cancelled_at | datetime | yes | | |
| reminder_sent_at | datetime | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_booking_settings`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | UNI |
| approval_mode | varchar(20) | no | instant | |
| pending_blocks_slot | tinyint(1) | no | 1 | |
| min_notice_minutes | int unsigned | no | 60 | |
| max_days_ahead | int unsigned | no | 60 | |
| slot_interval_minutes | smallint unsigned | no | 15 | |
| allow_guest_booking | tinyint(1) | no | 1 | |
| require_customer_phone | tinyint(1) | no | 0 | |
| allow_customer_cancellation | tinyint(1) | no | 1 | |
| cancellation_notice_minutes | int unsigned | no | 1440 | |
| allow_customer_reschedule | tinyint(1) | no | 1 | |
| reschedule_notice_minutes | int unsigned | no | 1440 | |
| payment_mode | varchar(30) | no | pay_later | |
| hold_minutes | smallint unsigned | no | 15 | |
| tax_rate_bps | int unsigned | no | 0 | |
| reminder_minutes_before | int unsigned | no | 1440 | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_branch_staff`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| branch_id | bigint unsigned | no | | PRI |
| staff_id | bigint unsigned | no | | PRI |

### `abs_branches`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| name | varchar(255) | no | | |
| address | varchar(255) | yes | | |
| phone | varchar(40) | yes | | |
| email | varchar(255) | yes | | |
| is_active | tinyint(1) | no | 1 | |
| sort_order | int unsigned | no | 0 | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_business_holidays`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| branch_id | bigint unsigned | yes | | MUL |
| date | date | no | | |
| name | varchar(255) | no | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_business_members`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| user_id | bigint unsigned | no | | MUL |
| role | varchar(20) | no | | |
| is_active | tinyint(1) | no | 1 | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_businesses`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| name | varchar(255) | no | | |
| slug | varchar(255) | no | | UNI |
| owner_user_id | bigint unsigned | yes | | MUL |
| description | text | yes | | |
| email | varchar(255) | yes | | |
| phone | varchar(40) | yes | | |
| address | varchar(255) | yes | | |
| timezone | varchar(64) | no | | |
| currency | char(3) | no | | |
| status | varchar(20) | no | active | MUL |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |
| deleted_at | timestamp | yes | | |

### `abs_invoices`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| appointment_id | bigint unsigned | no | | UNI |
| number | varchar(30) | no | | |
| issued_at | datetime | no | | |
| currency | char(3) | no | | |
| subtotal_minor | bigint unsigned | no | | |
| discount_minor | bigint unsigned | no | | |
| tax_minor | bigint unsigned | no | | |
| total_minor | bigint unsigned | no | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_notification_logs`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| appointment_id | bigint unsigned | yes | | MUL |
| notification | varchar(60) | no | | |
| channel | varchar(30) | no | | |
| recipient | varchar(255) | no | | |
| dedupe_key | varchar(255) | no | | UNI |
| status | varchar(20) | no | | |
| error | varchar(255) | yes | | |
| sent_at | datetime | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_payment_transactions`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| payment_id | bigint unsigned | no | | MUL |
| type | varchar(20) | no | | |
| status | varchar(20) | no | | |
| amount_minor | bigint unsigned | no | | |
| provider_reference | varchar(255) | yes | | |
| source | varchar(20) | no | | |
| meta | json | yes | | |
| created_at | timestamp | no | CURRENT_TIMESTAMP | |

### `abs_payments`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| appointment_id | bigint unsigned | no | | MUL |
| provider | varchar(30) | no | | MUL |
| purpose | varchar(20) | no | | |
| amount_minor | bigint unsigned | no | | |
| currency | char(3) | no | | |
| status | varchar(20) | no | | |
| provider_reference | varchar(255) | yes | | |
| provider_payment_id | varchar(255) | yes | | MUL |
| checkout_url | text | yes | | |
| refunded_minor | bigint unsigned | no | 0 | |
| paid_at | datetime | yes | | |
| failed_at | datetime | yes | | |
| failure_reason | varchar(255) | yes | | |
| recorded_by_user_id | bigint unsigned | yes | | MUL |
| notes | varchar(255) | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_refunds`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| payment_id | bigint unsigned | no | | MUL |
| amount_minor | bigint unsigned | no | | |
| currency | char(3) | no | | |
| status | varchar(20) | no | | |
| reason | varchar(255) | yes | | |
| provider_reference | varchar(255) | yes | | MUL |
| requested_by_user_id | bigint unsigned | yes | | MUL |
| processed_at | datetime | yes | | |
| failure_reason | varchar(255) | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_sequences`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| name | varchar(30) | no | | |
| next_value | bigint unsigned | no | 1 | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_service_categories`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| name | varchar(255) | no | | |
| sort_order | int unsigned | no | 0 | |
| is_active | tinyint(1) | no | 1 | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_services`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| category_id | bigint unsigned | yes | | MUL |
| name | varchar(255) | no | | |
| description | text | yes | | |
| duration_minutes | smallint unsigned | no | | |
| price_minor | bigint unsigned | no | 0 | |
| buffer_before_minutes | smallint unsigned | no | 0 | |
| buffer_after_minutes | smallint unsigned | no | 0 | |
| deposit_type | varchar(20) | no | none | |
| deposit_value | bigint unsigned | no | 0 | |
| is_active | tinyint(1) | no | 1 | |
| sort_order | int unsigned | no | 0 | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |
| deleted_at | timestamp | yes | | |

### `abs_staff`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| business_id | bigint unsigned | no | | MUL |
| user_id | bigint unsigned | yes | | MUL |
| name | varchar(255) | no | | |
| email | varchar(255) | yes | | |
| phone | varchar(40) | yes | | |
| title | varchar(255) | yes | | |
| bio | text | yes | | |
| is_active | tinyint(1) | no | 1 | |
| accepts_bookings | tinyint(1) | no | 1 | |
| sort_order | int unsigned | no | 0 | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |
| deleted_at | timestamp | yes | | |

### `abs_staff_breaks`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| staff_id | bigint unsigned | no | | MUL |
| weekday | tinyint unsigned | no | | |
| start_time | time | no | | |
| end_time | time | no | | |
| label | varchar(255) | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_staff_schedules`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| staff_id | bigint unsigned | no | | MUL |
| weekday | tinyint unsigned | no | | |
| start_time | time | no | | |
| end_time | time | no | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_staff_services`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| staff_id | bigint unsigned | no | | MUL |
| service_id | bigint unsigned | no | | MUL |
| price_minor | bigint unsigned | yes | | |
| duration_minutes | smallint unsigned | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_staff_time_off`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| staff_id | bigint unsigned | no | | MUL |
| starts_at | datetime | no | | |
| ends_at | datetime | no | | |
| reason | varchar(255) | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |

### `abs_webhook_events`

| Column | Type | Null | Default | Key |
|---|---|---|---|---|
| id | bigint unsigned | no | | PRI |
| provider | varchar(30) | no | | MUL |
| event_id | varchar(255) | no | | |
| type | varchar(100) | no | | |
| status | varchar(20) | no | | |
| payload_hash | char(64) | no | | |
| error | varchar(255) | yes | | |
| processed_at | datetime | yes | | |
| created_at | timestamp | yes | | |
| updated_at | timestamp | yes | | |
