<?php

namespace ME\Abs\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\NotificationLog;

/**
 * Every booking e-mail (and optional SMS). Queued; only ids are serialized, so no customer data sits in the queue.
 *
 * Dedupe: shouldSend() claims a row in abs_notification_logs keyed by $dedupeKey + channel. A row already
 * marked "sent" stops a retried job from sending again; a "failed" row may be retried.
 */
class AppointmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    private ?Appointment $loaded = null;

    /**
     * @param  array<string, scalar|null>  $extra
     */
    public function __construct(
        public int $appointmentId,
        public string $event,
        public string $audience,
        public string $dedupeKey,
        public array $extra = [],
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($this->audience === 'customer' && config('abs.sms_notifications') && $this->appointment()?->customer_phone) {
            $channels[] = MeSmsChannel::class;
        }

        return $channels;
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $appointment = $this->appointment();
        if (! $appointment) {
            return false;
        }

        $key = $this->dedupeKey.':'.($channel === 'mail' ? 'mail' : 'sms');
        $log = NotificationLog::query()->firstOrCreate(['dedupe_key' => $key], [
            'appointment_id' => $appointment->getKey(),
            'notification' => $this->event,
            'channel' => $channel === 'mail' ? 'mail' : 'sms',
            'recipient' => $this->maskedRecipient($notifiable, $channel),
            'status' => 'queued',
        ]);

        return $log->status !== 'sent';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appointment = $this->appointment();
        $copy = NotificationCopy::for($this->event, $this->audience, $appointment, $this->extra);

        return (new MailMessage)
            ->subject($copy['subject'])
            ->view('abs::mail.appointment', [
                'appointment' => $appointment,
                'copy' => $copy,
                'audience' => $this->audience,
                'title' => $copy['subject'],
            ]);
    }

    public function toMeSms(object $notifiable): string
    {
        $appointment = $this->appointment();
        $copy = NotificationCopy::for($this->event, $this->audience, $appointment, $this->extra);

        return $copy['subject'].' — '.$appointment->booking_reference.', '
            .$appointment->localStartsAt()->format('d M Y, h:i A');
    }

    /**
     * Called by the queue after the last retry failed.
     */
    public function failed(\Throwable $exception): void
    {
        NotificationLog::query()->where('dedupe_key', 'like', $this->dedupeKey.':%')->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'error' => mb_substr(class_basename($exception).': '.$exception->getMessage(), 0, 250)]);
    }

    public function logKey(string $channel): string
    {
        return $this->dedupeKey.':'.($channel === 'mail' ? 'mail' : 'sms');
    }

    public function appointment(): ?Appointment
    {
        return $this->loaded ??= Appointment::query()->with(['business', 'staff', 'items', 'branch'])->find($this->appointmentId);
    }

    private function maskedRecipient(object $notifiable, string $channel): string
    {
        $address = $channel === 'mail' ? $notifiable->routeNotificationFor('mail', $this) : $this->appointment()?->customer_phone;
        $address = is_array($address) ? (string) array_key_first($address) : (string) $address;

        if (str_contains($address, '@')) {
            [$name, $domain] = explode('@', $address, 2);

            return mb_substr($name, 0, 2).'***@'.$domain;
        }

        return str_repeat('*', max(0, strlen($address) - 3)).substr($address, -3);
    }
}
