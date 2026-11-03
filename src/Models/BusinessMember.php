<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ME\Abs\Enums\BusinessRole;
use ME\Models\User;

class BusinessMember extends Model
{
    protected $table = 'abs_business_members';

    protected $fillable = ['business_id', 'user_id', 'role', 'is_active'];

    protected function casts(): array
    {
        return [
            'role' => BusinessRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
