<?php

namespace ME\Abs\Enums;

enum AppointmentStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Expired = 'expired';

    /**
     * Allowed next statuses. Every status change goes through AppointmentStateMachine, which reads this map.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AwaitingPayment => [self::Pending, self::Confirmed, self::Cancelled, self::Expired],
            self::Pending => [self::Confirmed, self::Rejected, self::Cancelled],
            self::Confirmed => [self::CheckedIn, self::InProgress, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::InProgress, self::NoShow],
            self::InProgress => [self::Completed],
            self::Completed, self::Rejected, self::Cancelled, self::NoShow, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether an appointment in this status occupies its time slot. Pending is decided by the business
     * setting (pending_blocks_slot) and awaiting_payment only until its hold expires, so both are handled
     * by the availability query, not here.
     *
     * @return array<int, self>
     */
    public static function alwaysBlocking(): array
    {
        return [self::Confirmed, self::CheckedIn, self::InProgress, self::Completed, self::NoShow];
    }

    /**
     * Statuses that count toward gross booking value in reports.
     *
     * @return array<int, self>
     */
    public static function billable(): array
    {
        return [self::Pending, self::Confirmed, self::CheckedIn, self::InProgress, self::Completed, self::NoShow];
    }

    public function label(): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::Pending => 'Pending approval',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
            self::Expired => 'Expired',
        };
    }

    /**
     * Button text, Bootstrap colour and Font Awesome icon for moving an appointment INTO this status.
     *
     * @return array{label: string, color: string, icon: string}
     */
    public function actionMeta(): array
    {
        [$label, $color, $icon] = match ($this) {
            self::Confirmed => ['Approve / confirm', 'success', 'check'],
            self::Rejected => ['Reject', 'danger', 'times'],
            self::Cancelled => ['Cancel', 'danger', 'ban'],
            self::CheckedIn => ['Check in', 'info', 'door-open'],
            self::InProgress => ['Start', 'primary', 'play'],
            self::Completed => ['Complete', 'success', 'flag-checkered'],
            self::NoShow => ['No-show', 'secondary', 'user-slash'],
            default => [$this->label(), 'secondary', 'circle'],
        };

        return compact('label', 'color', 'icon');
    }

    public function color(): string
    {
        return match ($this) {
            self::AwaitingPayment, self::Pending => 'warning',
            self::Confirmed => 'primary',
            self::CheckedIn, self::InProgress => 'info',
            self::Completed => 'success',
            self::Rejected, self::Cancelled, self::NoShow => 'danger',
            self::Expired => 'secondary',
        };
    }
}
