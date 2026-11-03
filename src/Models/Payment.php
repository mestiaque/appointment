<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ME\Abs\Enums\PaymentPurpose;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Models\User;

/**
 * One charge attempt. status / refunded_minor change only through PaymentLedger.
 */
class Payment extends Model
{
    protected $table = 'abs_payments';

    protected $fillable = [
        'business_id', 'appointment_id', 'provider', 'purpose', 'amount_minor', 'currency', 'status',
        'provider_reference', 'provider_payment_id', 'checkout_url', 'paid_at', 'failed_at', 'failure_reason',
        'recorded_by_user_id', 'notes',
    ];

    protected $hidden = ['checkout_url'];

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentRecordStatus::class,
            'amount_minor' => 'integer',
            'refunded_minor' => 'integer',
            'paid_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class)->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /**
     * Secret in the callback path (gateways append their own query parameters, which would break a signed URL).
     */
    public function callbackToken(): string
    {
        return substr(hash_hmac('sha256', 'abs-payment-callback:'.$this->getKey(), (string) config('app.key')), 0, 32);
    }

    public function callbackUrl(): string
    {
        return route('abs.public.payment.callback', ['payment' => $this->getKey(), 'token' => $this->callbackToken()]);
    }

    public function refundableMinor(): int
    {
        if (! in_array($this->status, PaymentRecordStatus::captured(), true)) {
            return 0;
        }

        $reserved = (int) $this->refunds()->whereIn('status', ['requested', 'pending'])->sum('amount_minor');

        return max(0, $this->amount_minor - $this->refunded_minor - $reserved);
    }
}
