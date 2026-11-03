<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ME\Abs\Enums\ActorType;
use ME\Models\User;

class AppointmentRescheduleHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'abs_appointment_reschedule_histories';

    protected $fillable = [
        'appointment_id', 'old_starts_at', 'old_ends_at', 'old_staff_id', 'new_starts_at', 'new_ends_at',
        'new_staff_id', 'actor_user_id', 'actor_type', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'old_starts_at' => 'immutable_datetime',
            'old_ends_at' => 'immutable_datetime',
            'new_starts_at' => 'immutable_datetime',
            'new_ends_at' => 'immutable_datetime',
            'actor_type' => ActorType::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
