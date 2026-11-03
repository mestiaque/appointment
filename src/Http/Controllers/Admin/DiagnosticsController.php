<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\View\View;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Services\Diagnostics;

class DiagnosticsController extends Controller
{
    public function index(Diagnostics $diagnostics): View
    {
        return view('abs::admin.diagnostics', ['checks' => $diagnostics->runtime()]);
    }
}
