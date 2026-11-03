<?php

namespace ME\Abs\Support;

use ME\Models\Role;
use ME\Models\User;

/**
 * A "customer" is a user whose only metheme role is the customer role (or who has no role at all).
 * Customers use the customer panel and are kept out of the admin panel.
 */
final class CustomerAccount
{
    public const ROLE = 'customer';

    public static function isCustomer(?User $user): bool
    {
        if (! $user || (function_exists('me_is_developer') && me_is_developer($user))) {
            return false;
        }

        $slugs = $user->roles()->pluck('slug');

        return $slugs->isEmpty() || $slugs->every(fn ($slug) => $slug === self::ROLE);
    }

    public static function role(): Role
    {
        return Role::query()->firstOrCreate(['slug' => self::ROLE], [
            'name' => 'Customer',
            'description' => 'Self-registered booking customer (no admin access)',
            'parent_id' => Role::query()->whereIn('slug', ['super-admin', 'super_admin'])->value('id'),
            'color' => '#0ea5e9',
        ]);
    }
}
