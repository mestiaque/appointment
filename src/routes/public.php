<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use ME\Abs\Http\Controllers\Front\BookingController;
use ME\Abs\Http\Controllers\Front\LookupController;
use ME\Abs\Http\Controllers\Front\WebhookController;

/*
| Public booking pages: /{abs.public_path}/... (default /book). Guests manage a booking through a signed URL.
*/
Route::group([
    'prefix' => config('abs.public_path', 'book'),
    'as' => 'abs.public.',
    'middleware' => ['web'],
], function () {
    Route::get('/', [BookingController::class, 'directory'])->name('directory');
    Route::get('/find', [LookupController::class, 'show'])->name('find');
    Route::post('/find', [LookupController::class, 'lookup'])->middleware('throttle:abs-booking')->name('find.lookup');
    Route::post('/find/verify', [LookupController::class, 'verify'])->middleware('throttle:abs-booking')->name('find.verify');
    Route::post('/find/restart', [LookupController::class, 'restart'])->name('find.restart');
    // Return from the payment page. GET or POST (SSLCommerz posts back), so CSRF does not apply; the token in
    // the path proves the URL came from us and the gateway is asked server-side whether money was taken.
    Route::match(['get', 'post'], '/pay/{payment}/{token}/callback', [BookingController::class, 'paymentCallback'])
        ->whereNumber('payment')->where('token', '[a-f0-9]{32}')
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->middleware('throttle:abs-slots')->name('payment.callback');
    Route::get('/booking/{reference}/print', [BookingController::class, 'printSlip'])->middleware(['signed', 'throttle:abs-slots'])->name('print');
    Route::get('/my-bookings', [BookingController::class, 'mine'])->middleware('auth')->name('mine');
    Route::get('/booking/{reference}', [BookingController::class, 'manage'])->middleware(['signed', 'throttle:abs-slots'])->name('manage');
    Route::post('/booking/{reference}/cancel', [BookingController::class, 'cancel'])->middleware(['signed', 'throttle:abs-booking'])->name('cancel');
    Route::post('/booking/{reference}/pay', [BookingController::class, 'pay'])->middleware(['signed', 'throttle:abs-booking'])->name('pay');
    Route::get('/booking/{reference}/payment/{payment}/return', [BookingController::class, 'paymentReturn'])->middleware(['signed', 'throttle:abs-slots'])->name('payment.return');
    Route::get('/{slug}', [BookingController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('show');
});

// Provider webhooks: no session, no CSRF (signature-verified instead), rate limited.
Route::post('/abs/webhooks/{provider}/{business?}', WebhookController::class)
    ->middleware('throttle:abs-webhook')
    ->where(['provider' => '[a-z]+', 'business' => '[0-9]+'])
    ->name('abs.webhooks');
