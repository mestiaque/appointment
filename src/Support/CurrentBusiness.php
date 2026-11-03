<?php

namespace ME\Abs\Support;

use Illuminate\Contracts\Session\Session;
use ME\Abs\Models\Business;
use ME\Models\User;

/**
 * Business the logged-in user is working in. Stored in the session, re-validated on every request,
 * so a tampered or revoked id never gives access.
 */
class CurrentBusiness
{
    public const SESSION_KEY = 'abs.business_id';

    private ?Business $business = null;

    /** Who and what the cached result belongs to ("user id|session business id"); null = not resolved. */
    private ?string $resolvedFor = null;

    public function __construct(private BusinessAccess $access, private Session $session) {}

    public function get(): ?Business
    {
        /** @var User|null $user */
        $user = auth()->user();
        $id = $this->session->get(self::SESSION_KEY);

        // Re-resolve when the user or the stored id changed, so a long-lived process (Octane, queue, tests)
        // never hands one user's business to another.
        $key = ($user?->getKey() ?? 'guest').'|'.($id ?? '');
        if ($this->resolvedFor === $key) {
            return $this->business;
        }

        $this->resolvedFor = $key;
        $this->business = null;

        if (! $user) {
            return null;
        }

        $business = $id ? Business::query()->find($id) : null;

        if (! $business || ! $this->access->canAccess($user, $business)) {
            $business = $this->access->accessibleBusinesses($user)->first();
            $this->session->put(self::SESSION_KEY, $business?->getKey());
            $this->resolvedFor = $user->getKey().'|'.($business?->getKey() ?? '');
        }

        return $this->business = $business;
    }

    public function switchTo(Business $business): void
    {
        $this->session->put(self::SESSION_KEY, $business->getKey());
        $this->business = $business;
        $this->resolvedFor = (auth()->id() ?? 'guest').'|'.$business->getKey();
    }

    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
        $this->business = null;
        $this->resolvedFor = null;
    }
}
