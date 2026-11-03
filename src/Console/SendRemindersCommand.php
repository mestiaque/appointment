<?php

namespace ME\Abs\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\BookingSetting;
use ME\Abs\Services\Notifications\AppointmentNotifier;

/**
 * Sends one reminder per confirmed appointment, reminder_minutes_before its start.
 * Times are UTC instants, so the reminder fires at the right local time in every timezone.
 * The reminder_sent_at update is conditional (WHERE reminder_sent_at IS NULL), so overlapping runs cannot double-send.
 */
class SendRemindersCommand extends Command
{
    protected $signature = 'abs:send-reminders';

    protected $description = 'Send upcoming appointment reminders';

    public function handle(AppointmentNotifier $notifier): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        BookingSetting::query()->where('reminder_minutes_before', '>', 0)->each(function (BookingSetting $settings) use ($now, $notifier, &$sent) {
            Appointment::query()
                ->where('business_id', $settings->business_id)
                ->where('status', AppointmentStatus::Confirmed)
                ->whereNull('reminder_sent_at')
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<=', $now->addMinutes($settings->reminder_minutes_before))
                ->orderBy('id')
                ->chunkById(200, function ($appointments) use ($now, $notifier, &$sent) {
                    foreach ($appointments as $appointment) {
                        $claimed = Appointment::query()->whereKey($appointment->getKey())->whereNull('reminder_sent_at')
                            ->update(['reminder_sent_at' => $now]);

                        if ($claimed) {
                            $notifier->reminder($appointment);
                            $sent++;
                        }
                    }
                });
        });

        $this->info("Reminders queued: {$sent}");

        return self::SUCCESS;
    }
}
