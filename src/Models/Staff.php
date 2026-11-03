<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ME\Abs\Database\Factories\StaffFactory;
use ME\Models\User;

class Staff extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'abs_staff';

    protected $fillable = [
        'business_id', 'user_id', 'name', 'email', 'phone', 'title', 'bio', 'is_active', 'accepts_bookings', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepts_bookings' => 'boolean',
        ];
    }

    protected static function newFactory(): StaffFactory
    {
        return StaffFactory::new();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'abs_staff_services')
            ->withPivot(['price_minor', 'duration_minutes'])
            ->withTimestamps();
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'abs_branch_staff');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class);
    }

    public function breaks(): HasMany
    {
        return $this->hasMany(StaffBreak::class);
    }

    public function timeOff(): HasMany
    {
        return $this->hasMany(StaffTimeOff::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Staff that can take online bookings.
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('accepts_bookings', true);
    }
}
