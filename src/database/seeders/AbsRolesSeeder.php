<?php

namespace ME\Abs\Database\Seeders;

use Illuminate\Database\Seeder;
use ME\Abs\Support\CustomerAccount;
use ME\Models\Role;
use ME\Models\RolePermission;

/**
 * Creates the metheme roles used by the booking module. Idempotent: existing roles keep any extra
 * permissions an admin added; missing booking permissions are added.
 */
class AbsRolesSeeder extends Seeder
{
    /**
     * @return array<string, array{name: string, color: string, permissions: array<int, string>}>
     */
    public static function roles(): array
    {
        $all = [];
        foreach (require __DIR__.'/../../Config/permission.php' as $module => $data) {
            foreach (explode(',', $data['actions']) as $action) {
                $all[] = "{$module}.{$action}";
            }
        }

        return [
            'booking-owner' => [
                'name' => 'Booking Owner',
                'color' => '#0d6efd',
                'permissions' => array_values(array_diff($all, ['abs_business.all', 'abs_diagnostic.view'])),
            ],
            'booking-receptionist' => [
                'name' => 'Booking Receptionist',
                'color' => '#20c997',
                'permissions' => [
                    'abs_business.view', 'abs_branch.view', 'abs_service.view', 'abs_staff.view', 'abs_schedule.view',
                    'abs_appointment.view', 'abs_appointment.create', 'abs_appointment.edit', 'abs_appointment.approve',
                    'abs_appointment.cancel', 'abs_appointment.reschedule', 'abs_appointment.status',
                    'abs_payment.view', 'abs_payment.record', 'abs_report.view',
                ],
            ],
            'booking-staff' => [
                'name' => 'Booking Staff',
                'color' => '#6f42c1',
                'permissions' => ['abs_business.view', 'abs_schedule.view', 'abs_appointment.view', 'abs_appointment.status'],
            ],
            // Self-registered customers: no admin permission at all; they use the customer panel only.
            CustomerAccount::ROLE => [
                'name' => 'Customer',
                'color' => '#0ea5e9',
                'permissions' => [],
            ],
        ];
    }

    public function run(): void
    {
        $parentId = Role::query()->whereIn('slug', ['super-admin', 'super_admin'])->value('id');

        foreach (self::roles() as $slug => $definition) {
            $role = Role::query()->firstOrCreate(['slug' => $slug], [
                'name' => $definition['name'],
                'description' => 'Appointment Booking module',
                'parent_id' => $parentId,
                'color' => $definition['color'],
            ]);

            $rolePermission = RolePermission::query()->firstOrNew(['role_id' => $role->getKey()]);
            $rolePermission->permissions = array_values(array_unique(array_merge((array) $rolePermission->permissions, $definition['permissions'])));
            $rolePermission->save();
        }
    }
}
