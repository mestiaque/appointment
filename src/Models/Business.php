<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use ME\Abs\Database\Factories\BusinessFactory;
use ME\Abs\Enums\BusinessStatus;
use ME\Models\User;
use ME\Traits\HasMedia;

class Business extends Model
{
    use HasFactory, HasMedia, SoftDeletes;

    protected $table = 'abs_businesses';

    protected $fillable = [
        'name', 'slug', 'owner_user_id', 'description', 'email', 'phone', 'address', 'timezone', 'currency', 'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => BusinessStatus::class,
        ];
    }

    protected static function newFactory(): BusinessFactory
    {
        return BusinessFactory::new();
    }

    protected function mediaCollections(): array
    {
        return [
            'logo' => ['single' => true, 'mimes' => 'jpg,jpeg,png,webp', 'max_kb' => 2048, 'conversions' => ['thumb' => 200]],
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(BusinessMember::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function serviceCategories(): HasMany
    {
        return $this->hasMany(ServiceCategory::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(BusinessHoliday::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function bookingSetting(): HasOne
    {
        return $this->hasOne(BookingSetting::class);
    }

    /**
     * Booking settings, created with defaults the first time they are needed.
     */
    public function settings(): BookingSetting
    {
        if (! $this->bookingSetting) {
            $this->setRelation('bookingSetting', $this->bookingSetting()->firstOrCreate([]));
        }

        return $this->bookingSetting;
    }

    public function isActive(): bool
    {
        return $this->status === BusinessStatus::Active;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BusinessStatus::Active);
    }
}
