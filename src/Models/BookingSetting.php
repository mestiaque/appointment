<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ME\Abs\Enums\ApprovalMode;
use ME\Abs\Enums\PaymentMode;

class BookingSetting extends Model
{
    protected $table = 'abs_booking_settings';

    protected $fillable = [
        'business_id', 'approval_mode', 'pending_blocks_slot', 'min_notice_minutes', 'max_days_ahead',
        'slot_interval_minutes', 'allow_guest_booking', 'require_customer_phone', 'allow_customer_cancellation',
        'cancellation_notice_minutes', 'allow_customer_reschedule', 'reschedule_notice_minutes', 'payment_mode',
        'hold_minutes', 'tax_rate_bps', 'reminder_minutes_before',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'approval_mode' => 'instant',
        'pending_blocks_slot' => true,
        'min_notice_minutes' => 60,
        'max_days_ahead' => 60,
        'slot_interval_minutes' => 15,
        'allow_guest_booking' => true,
        'require_customer_phone' => false,
        'allow_customer_cancellation' => true,
        'cancellation_notice_minutes' => 1440,
        'allow_customer_reschedule' => true,
        'reschedule_notice_minutes' => 1440,
        'payment_mode' => 'pay_later',
        'hold_minutes' => 15,
        'tax_rate_bps' => 0,
        'reminder_minutes_before' => 1440,
    ];

    protected function casts(): array
    {
        return [
            'approval_mode' => ApprovalMode::class,
            'payment_mode' => PaymentMode::class,
            'pending_blocks_slot' => 'boolean',
            'allow_guest_booking' => 'boolean',
            'require_customer_phone' => 'boolean',
            'allow_customer_cancellation' => 'boolean',
            'allow_customer_reschedule' => 'boolean',
            'min_notice_minutes' => 'integer',
            'max_days_ahead' => 'integer',
            'slot_interval_minutes' => 'integer',
            'cancellation_notice_minutes' => 'integer',
            'reschedule_notice_minutes' => 'integer',
            'hold_minutes' => 'integer',
            'tax_rate_bps' => 'integer',
            'reminder_minutes_before' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
