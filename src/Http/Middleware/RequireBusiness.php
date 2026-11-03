<?php

namespace ME\Abs\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use ME\Abs\Support\CurrentBusiness;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current business and shares it with views. Without one, the user is sent to the business list.
 */
class RequireBusiness
{
    public function __construct(private CurrentBusiness $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $business = $this->current->get();

        if (! $business) {
            return redirect()->route('abs.businesses.index')
                ->with('error', __('abs::abs.no_business_selected'));
        }

        View::share('currentBusiness', $business);

        return $next($request);
    }
}
