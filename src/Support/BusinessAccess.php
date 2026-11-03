<?php

namespace ME\Abs\Support;

use Illuminate\Database\Eloquent\Builder;
use ME\Abs\Enums\BusinessRole;
use ME\Abs\Models\Business;
use ME\Abs\Models\BusinessMember;
use ME\Models\User;

/**
 * The single place that decides whether a user may act on a business.
 *
 * Rule: metheme permission (hasPermission) AND (privileged OR active membership whose role allows it).
 * "Privileged" is the explicit permission abs_business.all — metheme gives it to super-admin roles and developers.
 */
class BusinessAccess
{
    /** @var array<string, ?BusinessMember> */
    private array $membershipCache = [];

    public function isPrivileged(?User $user): bool
    {
        return $user !== null && $user->hasPermission('abs_business.all');
    }

    public function membership(?User $user, Business $business): ?BusinessMember
    {
        if (! $user) {
            return null;
        }

        $key = $user->getKey().':'.$business->getKey();

        return $this->membershipCache[$key] ??= BusinessMember::query()
            ->where('business_id', $business->getKey())
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();
    }

    public function role(?User $user, Business $business): ?BusinessRole
    {
        return $this->membership($user, $business)?->role;
    }

    public function canAccess(?User $user, Business $business): bool
    {
        return $this->isPrivileged($user) || $this->membership($user, $business) !== null;
    }

    public function can(?User $user, Business $business, string $permission): bool
    {
        if (! $user || ! $user->hasPermission($permission)) {
            return false;
        }

        if ($this->isPrivileged($user)) {
            return true;
        }

        return $this->role($user, $business)?->allows($permission) ?? false;
    }

    /**
     * Staff id the user is limited to inside this business, or null when they may see every appointment.
     */
    public function restrictedStaffId(?User $user, Business $business): ?int
    {
        if (! $user || $this->isPrivileged($user)) {
            return null;
        }

        if (! $this->role($user, $business)?->seesOnlyOwnAppointments()) {
            return null;
        }

        // A staff-role member without a staff profile sees nothing (id 0 matches no row).
        return (int) $business->staff()->where('user_id', $user->getKey())->value('id');
    }

    /**
     * Businesses the user may open.
     *
     * @return Builder<Business>
     */
    public function accessibleBusinesses(?User $user): Builder
    {
        $query = Business::query()->orderBy('name');

        if ($this->isPrivileged($user)) {
            return $query;
        }

        return $query->whereIn('id', BusinessMember::query()
            ->select('business_id')
            ->where('user_id', $user?->getKey() ?? 0)
            ->where('is_active', true));
    }
}
