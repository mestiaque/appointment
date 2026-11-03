<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of the booked service (name, duration, price) at booking time.
 */
class AppointmentItem extends Model
{
    protected $table = 'abs_appointment_items';

    protected $fillable = [
        'appointment_id', 'service_id', 'service_name', 'duration_minutes', 'buffer_before_minutes',
        'buffer_after_minutes', 'unit_price_minor', 'quantity', 'line_total_minor',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'unit_price_minor' => 'integer',
            'quantity' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
