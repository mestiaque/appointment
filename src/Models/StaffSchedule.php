<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Weekly interval in the business's local wall-clock time. weekday: 0 = Sunday ... 6 = Saturday.
 */
class StaffSchedule extends Model
{
    protected $table = 'abs_staff_schedules';

    protected $fillable = ['staff_id', 'weekday', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
