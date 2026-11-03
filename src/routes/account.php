<?php

use Illuminate\Support\Facades\Route;
use ME\Abs\Http\Controllers\Account\AccountController;
use ME\Abs\Http\Controllers\Account\AuthController;

/*
| Customer panel: /{abs.account_path}/... (default /account). Separate from metheme's admin login.
*/
Route::group([
    'prefix' => config('abs.account_path', 'account'),
    'as' => 'abs.account.',
    'middleware' => ['web'],
], function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login.store');
        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register.store');
    });

    // "auth" sends guests to metheme's login route; customer pages point them to the customer login instead.
    Route::middleware(['auth:web'])->group(function () {
        Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');
        Route::get('/profile', [AccountController::class, 'profile'])->name('profile');
        Route::put('/profile', [AccountController::class, 'updateProfile'])->name('profile.update');
        Route::put('/password', [AccountController::class, 'updatePassword'])->name('password.update');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});
