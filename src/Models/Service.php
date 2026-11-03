<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ME\Abs\Database\Factories\ServiceFactory;
use ME\Abs\Enums\DepositType;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'abs_services';

    protected $fillable = [
        'business_id', 'category_id', 'name', 'description', 'duration_minutes', 'price_minor',
        'buffer_before_minutes', 'buffer_after_minutes', 'deposit_type', 'deposit_value', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'deposit_type' => DepositType::class,
            'is_active' => 'boolean',
            'duration_minutes' => 'integer',
            'price_minor' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'deposit_value' => 'integer',
        ];
    }

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'abs_staff_services')
            ->withPivot(['price_minor', 'duration_minutes'])
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
