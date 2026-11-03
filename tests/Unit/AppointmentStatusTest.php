<?php

namespace ME\Abs\Tests\Unit;

use ME\Abs\Enums\AppointmentStatus as S;
use ME\Abs\Enums\BusinessRole;
use PHPUnit\Framework\TestCase;

class AppointmentStatusTest extends TestCase
{
    public function test_documented_transitions_are_allowed(): void
    {
        $allowed = [
            [S::Pending, S::Confirmed], [S::Pending, S::Rejected], [S::Pending, S::Cancelled],
            [S::Confirmed, S::CheckedIn], [S::Confirmed, S::InProgress], [S::CheckedIn, S::InProgress],
            [S::InProgress, S::Completed], [S::Confirmed, S::Cancelled], [S::Confirmed, S::NoShow],
            [S::AwaitingPayment, S::Pending], [S::AwaitingPayment, S::Confirmed], [S::AwaitingPayment, S::Expired],
        ];

        foreach ($allowed as [$from, $to]) {
            $this->assertTrue($from->canTransitionTo($to), "{$from->value} -> {$to->value}");
        }
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $refused = [
            [S::Pending, S::Completed], [S::Confirmed, S::Pending], [S::Completed, S::Cancelled],
            [S::Cancelled, S::Confirmed], [S::Rejected, S::Confirmed], [S::InProgress, S::Cancelled],
            [S::Expired, S::Confirmed], [S::NoShow, S::Completed], [S::Pending, S::NoShow],
        ];

        foreach ($refused as [$from, $to]) {
            $this->assertFalse($from->canTransitionTo($to), "{$from->value} -> {$to->value}");
        }
    }

    public function test_final_statuses(): void
    {
        foreach ([S::Completed, S::Rejected, S::Cancelled, S::NoShow, S::Expired] as $status) {
            $this->assertTrue($status->isFinal());
        }
        $this->assertFalse(S::Confirmed->isFinal());
    }

    public function test_business_role_ceilings(): void
    {
        $this->assertTrue(BusinessRole::Owner->allows('abs_business.delete'));
        $this->assertFalse(BusinessRole::Manager->allows('abs_business.delete'));
        $this->assertTrue(BusinessRole::Manager->allows('abs_payment.refund'));
        $this->assertFalse(BusinessRole::Receptionist->allows('abs_payment.refund'));
        $this->assertFalse(BusinessRole::Receptionist->allows('abs_report.export'));
        $this->assertTrue(BusinessRole::Staff->allows('abs_appointment.status'));
        $this->assertFalse(BusinessRole::Staff->allows('abs_appointment.cancel'));
        $this->assertTrue(BusinessRole::Staff->seesOnlyOwnAppointments());
    }
}
