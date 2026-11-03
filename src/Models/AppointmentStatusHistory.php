<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Models\User;

class AppointmentStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'abs_appointment_status_histories';

    protected $fillable = ['appointment_id', 'from_status', 'to_status', 'actor_user_id', 'actor_type', 'reason'];

    protected function casts(): array
    {
        return [
            'from_status' => AppointmentStatus::class,
            'to_status' => AppointmentStatus::class,
            'actor_type' => ActorType::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
