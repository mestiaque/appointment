<?php

namespace ME\Abs\Livewire\Concerns;

use ME\Abs\Models\Business;
use ME\Abs\Support\BusinessAccess;
use ME\Abs\Support\CurrentBusiness;

/**
 * Admin Livewire components: the business is re-resolved from the session on every request (never stored in a
 * public property), and each action checks the permission again — route middleware does not run on Livewire updates.
 */
trait InteractsWithBusiness
{
    protected function business(): Business
    {
        $business = app(CurrentBusiness::class)->get();
        abort_unless($business, 403);

        return $business;
    }

    protected function authorizeBusiness(string $permission): void
    {
        abort_unless(app(BusinessAccess::class)->can(auth()->user(), $this->business(), $permission), 403);
    }

    protected function restrictedStaffId(): ?int
    {
        return app(BusinessAccess::class)->restrictedStaffId(auth()->user(), $this->business());
    }
}
