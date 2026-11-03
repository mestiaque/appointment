<?php

namespace ME\Abs\Enums;

/**
 * Role of a user inside one business (abs_business_members.role).
 *
 * A user needs both the metheme permission (from their metheme role) and a membership role that allows it.
 * The membership role is a ceiling per business; it never grants a permission the metheme role lacks.
 */
enum BusinessRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Receptionist = 'receptionist';
    case Staff = 'staff';

    public function allows(string $permission): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Manager => $permission !== 'abs_business.delete',
            self::Receptionist => in_array($permission, [
                'abs_business.view', 'abs_branch.view', 'abs_service.view', 'abs_staff.view', 'abs_schedule.view',
                'abs_appointment.view', 'abs_appointment.create', 'abs_appointment.edit', 'abs_appointment.approve',
                'abs_appointment.cancel', 'abs_appointment.reschedule', 'abs_appointment.status',
                'abs_payment.view', 'abs_payment.record', 'abs_report.view',
            ], true),
            self::Staff => in_array($permission, [
                'abs_business.view', 'abs_schedule.view', 'abs_appointment.view', 'abs_appointment.status',
            ], true),
        };
    }

    /**
     * Staff members only see appointments assigned to their own staff profile.
     */
    public function seesOnlyOwnAppointments(): bool
    {
        return $this === self::Staff;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
