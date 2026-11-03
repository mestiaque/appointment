<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $table = 'abs_invoices';

    protected $fillable = [
        'business_id', 'appointment_id', 'number', 'issued_at', 'currency', 'subtotal_minor', 'discount_minor',
        'tax_minor', 'total_minor',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
