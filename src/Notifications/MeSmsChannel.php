<?php

namespace ME\Abs\Notifications;

use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Optional SMS adapter on top of metheme's me_sms() (SMS Configuration page). Off unless
 * config('abs.sms_notifications') is true; the booking flow never depends on it.
 */
class MeSmsChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof AppointmentNotification || ! function_exists('me_sms')) {
            return;
        }

        $phone = $notification->appointment()?->customer_phone;
        if (! $phone) {
            return;
        }

        if (! me_sms($phone, $notification->toMeSms($notifiable))) {
            throw new RuntimeException('SMS gateway rejected the message.');
        }
    }
}
