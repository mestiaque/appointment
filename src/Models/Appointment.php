<?php

namespace ME\Abs\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;
use ME\Abs\Database\Factories\AppointmentFactory;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\ApprovalMode;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Support\Currency;
use ME\Models\User;
use ME\Traits\HasMedia;

/**
 * Appointment. status and payment_status are not fillable: they change only through
 * AppointmentStateMachine and PaymentLedger.
 */
class Appointment extends Model
{
    use HasFactory, HasMedia;

    protected $table = 'abs_appointments';

    protected $fillable = [
        'business_id', 'branch_id', 'staff_id', 'service_id', 'customer_user_id', 'booking_reference',
        'customer_name', 'customer_email', 'customer_phone', 'starts_at', 'ends_at',
        'blocked_starts_at', 'blocked_ends_at', 'timezone', 'approval_mode', 'payment_method', 'currency',
        'subtotal_minor', 'discount_minor', 'tax_minor', 'tax_rate_bps', 'total_minor', 'deposit_minor', 'customer_notes',
        'internal_notes', 'source', 'created_by_user_id', 'hold_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'approval_mode' => ApprovalMode::class,
            'payment_status' => PaymentStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'blocked_starts_at' => 'immutable_datetime',
            'blocked_ends_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'tax_rate_bps' => 'integer',
            'total_minor' => 'integer',
            'deposit_minor' => 'integer',
            'paid_minor' => 'integer',
            'refunded_minor' => 'integer',
        ];
    }

    /**
     * Prescriptions, reports and other files the business shares with the customer.
     * Stored on the non-public "local" disk and served only through short-lived signed URLs.
     */
    protected function mediaCollections(): array
    {
        return [
            'documents' => ['mimes' => 'pdf,jpg,jpeg,png,webp', 'max_kb' => 10240, 'visibility' => 'private', 'disk' => 'local', 'conversions' => []],
        ];
    }

    protected static function newFactory(): AppointmentFactory
    {
        return AppointmentFactory::new();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class)->orderBy('id');
    }

    public function rescheduleHistories(): HasMany
    {
        return $this->hasMany(AppointmentRescheduleHistory::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * Balance still owed: total minus net collected (payments minus refunds).
     */
    public function outstandingMinor(): int
    {
        return max(0, $this->total_minor - ($this->paid_minor - $this->refunded_minor));
    }

    public function money(int $minor): string
    {
        return Currency::format($minor, $this->currency);
    }

    /**
     * Signed link that lets a guest view, cancel or reschedule this booking without an account.
     */
    public function manageUrl(): string
    {
        return URL::signedRoute('abs.public.manage', ['reference' => $this->booking_reference]);
    }

    public function localStartsAt(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->timezone);
    }

    public function localEndsAt(): CarbonImmutable
    {
        return $this->ends_at->setTimezone($this->timezone);
    }

    public function scopeStartingBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where('starts_at', '>=', $from)->where('starts_at', '<', $to);
    }
}
