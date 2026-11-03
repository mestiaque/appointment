<?php

namespace ME\Abs\Listeners;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use ME\Abs\Models\NotificationLog;
use ME\Abs\Notifications\AppointmentNotification;

/**
 * Marks abs_notification_logs rows as sent/failed. The error text is truncated and never includes the message body.
 */
class RecordNotificationDelivery
{
    public function sent(NotificationSent $event): void
    {
        if ($event->notification instanceof AppointmentNotification) {
            NotificationLog::query()->where('dedupe_key', $event->notification->logKey($event->channel))
                ->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        }
    }

    public function failed(NotificationFailed $event): void
    {
        if ($event->notification instanceof AppointmentNotification) {
            $error = $event->data['exception'] ?? null;
            NotificationLog::query()->where('dedupe_key', $event->notification->logKey($event->channel))
                ->update(['status' => 'failed', 'error' => $error instanceof \Throwable ? mb_substr(class_basename($error).': '.$error->getMessage(), 0, 250) : 'failed']);
        }
    }
}
