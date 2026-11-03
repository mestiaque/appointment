<?php

namespace ME\Abs\Notifications;

use ME\Abs\Models\Appointment;

/**
 * Subject and intro text for each notification event.
 */
final class NotificationCopy
{
    /**
     * @param  array<string, scalar|null>  $extra
     * @return array{subject: string, intro: string, show_manage_link: bool}
     */
    public static function for(string $event, string $audience, Appointment $appointment, array $extra = []): array
    {
        $business = $appointment->business->name;
        $customer = $audience === 'customer';

        [$subject, $intro] = match ($event) {
            'booking_received' => $customer
                ? ["Booking request received — {$business}", 'We received your booking request. The business will review it and you will get another e-mail once it is approved.']
                : ['New booking request needs approval', "{$appointment->customer_name} requested an appointment. Approve or reject it from the admin panel."],
            'booking_confirmed' => $customer
                ? ["Booking confirmed — {$business}", 'Your appointment is confirmed. We look forward to seeing you.']
                : ['New booking', "{$appointment->customer_name} booked an appointment."],
            'booking_approved' => ["Booking approved — {$business}", 'Good news: your booking request was approved.'],
            'booking_rejected' => ["Booking request declined — {$business}", 'Unfortunately the business could not accept your booking request.'.(($extra['reason'] ?? null) ? ' Reason: '.$extra['reason'] : '')],
            'booking_cancelled' => $customer
                ? ["Booking cancelled — {$business}", 'Your appointment has been cancelled.']
                : ['Booking cancelled', "The appointment of {$appointment->customer_name} was cancelled."],
            'appointment_rescheduled' => $customer
                ? ["Appointment rescheduled — {$business}", 'Your appointment has a new time. The updated details are below.']
                : ['Appointment rescheduled', "The appointment of {$appointment->customer_name} was moved."],
            'appointment_reminder' => ["Reminder: your appointment at {$business}", 'This is a reminder of your upcoming appointment.'],
            'payment_received' => ["Payment received — {$business}", 'Thank you. We received your payment of '.($extra['amount'] ?? '').'.'],
            'payment_failed' => ["Payment failed — {$business}", 'Your payment could not be completed. No money was taken. You can try again from your booking page.'],
            'refund_status' => ["Refund update — {$business}", 'Refund of '.($extra['amount'] ?? '').' is now '.($extra['status'] ?? 'updated').'.'],
            default => ["Booking update — {$business}", 'There is an update to your booking.'],
        };

        return [
            'subject' => $subject,
            'intro' => $intro,
            'show_manage_link' => $customer && ! in_array($event, ['booking_rejected', 'booking_cancelled'], true),
        ];
    }
}
