<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gateway credentials of one business. credentials is encrypted at rest with APP_KEY.
 */
class PaymentGatewaySetting extends Model
{
    protected $table = 'abs_payment_gateways';

    protected $fillable = ['business_id', 'provider', 'is_enabled', 'is_sandbox', 'credentials', 'sort_order'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_sandbox' => 'boolean',
            'credentials' => 'encrypted:array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
