<?php

namespace ME\Abs\Http\Controllers;

use ME\Abs\Models\Business;
use ME\Abs\Support\BusinessAccess;
use ME\Abs\Support\CurrentBusiness;
use ME\Http\Controllers\Controller as MethemeController;

abstract class Controller extends MethemeController
{
    /**
     * Current business, already validated by the abs.business middleware.
     */
    protected function business(): Business
    {
        $business = app(CurrentBusiness::class)->get();
        abort_unless($business, 403);

        return $business;
    }

    protected function canInBusiness(string $permission): bool
    {
        return app(BusinessAccess::class)->can(auth()->user(), $this->business(), $permission);
    }

    protected function authorizeInBusiness(string $permission): void
    {
        abort_unless($this->canInBusiness($permission), 403);
    }

    protected function pageSize(): int
    {
        return max(5, min(100, (int) (function_exists('get_setting') ? get_setting('pagination', 15) : 15)));
    }
}
