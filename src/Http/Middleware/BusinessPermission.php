<?php

namespace ME\Abs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ME\Abs\Support\BusinessAccess;
use ME\Abs\Support\CurrentBusiness;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('abs.can:abs_service.edit'). Runs after RequireBusiness.
 */
class BusinessPermission
{
    public function __construct(private CurrentBusiness $current, private BusinessAccess $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $business = $this->current->get();

        abort_unless($business && $this->access->can($request->user(), $business, $permission), 403);

        return $next($request);
    }
}
