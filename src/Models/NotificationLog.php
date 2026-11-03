<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per notification delivery key; the unique dedupe_key stops a retry from sending twice.
 */
class NotificationLog extends Model
{
    protected $table = 'abs_notification_logs';

    protected $fillable = ['appointment_id', 'notification', 'channel', 'recipient', 'dedupe_key', 'status', 'error', 'sent_at'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }
}
