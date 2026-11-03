<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history of payment events (charge, failure, refund ...). meta never holds card data.
 */
class PaymentTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'abs_payment_transactions';

    protected $fillable = ['payment_id', 'type', 'status', 'amount_minor', 'provider_reference', 'source', 'meta'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'amount_minor' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
