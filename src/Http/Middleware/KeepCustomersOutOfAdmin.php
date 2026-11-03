<?php

namespace ME\Abs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ME\Abs\Support\CustomerAccount;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends logged-in customers from any admin URL (/{me_prefix()}…) to the customer panel.
 * Runs in the "web" group, so it also covers metheme's own login redirect to the admin home.
 */
class KeepCustomersOutOfAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $prefix = function_exists('me_prefix') ? trim(me_prefix(), '/') : 'admin';

        if ($prefix !== '' && $request->user() && $request->is($prefix, $prefix.'/*') && CustomerAccount::isCustomer($request->user())) {
            return redirect()->route('abs.account.dashboard');
        }

        return $next($request);
    }
}
