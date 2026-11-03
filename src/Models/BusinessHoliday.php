<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Closed local calendar date. branch_id null = every branch.
 */
class BusinessHoliday extends Model
{
    protected $table = 'abs_business_holidays';

    protected $fillable = ['business_id', 'branch_id', 'date', 'name'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
