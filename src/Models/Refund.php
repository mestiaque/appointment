<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ME\Abs\Enums\RefundStatus;
use ME\Models\User;

class Refund extends Model
{
    protected $table = 'abs_refunds';

    protected $fillable = [
        'business_id', 'payment_id', 'amount_minor', 'currency', 'status', 'reason', 'provider_reference',
        'requested_by_user_id', 'processed_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
