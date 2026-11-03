<?php

use Illuminate\Support\Facades\Route;
use ME\Abs\Http\Controllers\Admin\AppointmentController;
use ME\Abs\Http\Controllers\Admin\BranchController;
use ME\Abs\Http\Controllers\Admin\BusinessController;
use ME\Abs\Http\Controllers\Admin\CalendarController;
use ME\Abs\Http\Controllers\Admin\CategoryController;
use ME\Abs\Http\Controllers\Admin\DashboardController;
use ME\Abs\Http\Controllers\Admin\DiagnosticsController;
use ME\Abs\Http\Controllers\Admin\GatewayController;
use ME\Abs\Http\Controllers\Admin\HolidayController;
use ME\Abs\Http\Controllers\Admin\MemberController;
use ME\Abs\Http\Controllers\Admin\PaymentController;
use ME\Abs\Http\Controllers\Admin\ReportController;
use ME\Abs\Http\Controllers\Admin\ServiceController;
use ME\Abs\Http\Controllers\Admin\SettingController;
use ME\Abs\Http\Controllers\Admin\StaffController;
use ME\Http\Middleware\LocaleMiddleware;

/*
| Admin pages: /{me_prefix()}/{abs.admin_path}/..., names "abs.*". Every business-scoped route runs
| abs.business (resolve + validate the current business) and abs.can:<permission> (metheme permission AND
| membership role). Records are always loaded through the current business, so ids from another business 404.
*/
Route::group([
    'prefix' => trim(me_prefix().'/'.config('abs.admin_path', 'booking'), '/'),
    'as' => 'abs.',
    'middleware' => ['web', 'auth', LocaleMiddleware::class, 'activityLog', 'abs.headers'],
], function () {
    Route::get('/businesses', [BusinessController::class, 'index'])->middleware('authorization:abs_business.view')->name('businesses.index');
    Route::get('/businesses/create', [BusinessController::class, 'create'])->middleware('authorization:abs_business.create')->name('businesses.create');
    Route::post('/businesses', [BusinessController::class, 'store'])->middleware('authorization:abs_business.create')->name('businesses.store');
    Route::post('/businesses/{business}/switch', [BusinessController::class, 'switch'])->middleware('authorization:abs_business.view')->name('businesses.switch');

    Route::middleware('abs.business')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->middleware('abs.can:abs_appointment.view')->name('dashboard');

        Route::get('/business/edit', [BusinessController::class, 'edit'])->middleware('abs.can:abs_business.edit')->name('businesses.edit');
        Route::put('/business', [BusinessController::class, 'update'])->middleware('abs.can:abs_business.edit')->name('businesses.update');
        Route::delete('/business', [BusinessController::class, 'destroy'])->middleware('abs.can:abs_business.delete')->name('businesses.destroy');

        Route::get('/members', [MemberController::class, 'index'])->middleware('abs.can:abs_business.edit')->name('members.index');
        Route::post('/members', [MemberController::class, 'store'])->middleware('abs.can:abs_business.edit')->name('members.store');
        Route::put('/members/{member}', [MemberController::class, 'update'])->middleware('abs.can:abs_business.edit')->name('members.update');
        Route::delete('/members/{member}', [MemberController::class, 'destroy'])->middleware('abs.can:abs_business.edit')->name('members.destroy');

        Route::get('/payment-gateways', [GatewayController::class, 'index'])->middleware('abs.can:abs_setting.view')->name('gateways.index');
        Route::put('/payment-gateways/{provider}', [GatewayController::class, 'update'])->middleware('abs.can:abs_setting.edit')->whereIn('provider', ['bkash', 'nagad', 'sslcommerz', 'stripe', 'paypal'])->name('gateways.update');
        Route::post('/payment-gateways/{provider}/test', [GatewayController::class, 'test'])->middleware(['abs.can:abs_setting.edit', 'throttle:10,1'])->whereIn('provider', ['bkash', 'nagad', 'sslcommerz', 'stripe', 'paypal'])->name('gateways.test');
        Route::get('/settings', [SettingController::class, 'edit'])->middleware('abs.can:abs_setting.view')->name('settings.edit');
        Route::put('/settings', [SettingController::class, 'update'])->middleware('abs.can:abs_setting.edit')->name('settings.update');

        foreach ([
            'branches' => [BranchController::class, 'abs_branch', 'branch'],
            'categories' => [CategoryController::class, 'abs_service', 'category'],
            'services' => [ServiceController::class, 'abs_service', 'service'],
            'staff' => [StaffController::class, 'abs_staff', 'staff'],
        ] as $uri => [$controller, $module, $parameter]) {
            Route::get("/{$uri}", [$controller, 'index'])->middleware("abs.can:{$module}.view")->name("{$uri}.index");
            Route::get("/{$uri}/create", [$controller, 'create'])->middleware("abs.can:{$module}.create")->name("{$uri}.create");
            Route::post("/{$uri}", [$controller, 'store'])->middleware("abs.can:{$module}.create")->name("{$uri}.store");
            Route::get("/{$uri}/{{$parameter}}/edit", [$controller, 'edit'])->middleware("abs.can:{$module}.edit")->name("{$uri}.edit");
            Route::put("/{$uri}/{{$parameter}}", [$controller, 'update'])->middleware("abs.can:{$module}.edit")->name("{$uri}.update");
            Route::delete("/{$uri}/{{$parameter}}", [$controller, 'destroy'])->middleware("abs.can:{$module}.delete")->name("{$uri}.destroy");
        }

        Route::get('/staff/{staff}/schedule', [StaffController::class, 'schedule'])->middleware('abs.can:abs_schedule.view')->name('staff.schedule');
        Route::post('/staff/{staff}/time-off', [StaffController::class, 'storeTimeOff'])->middleware('abs.can:abs_schedule.edit')->name('staff.time-off.store');
        Route::delete('/staff/{staff}/time-off/{timeOff}', [StaffController::class, 'destroyTimeOff'])->middleware('abs.can:abs_schedule.edit')->name('staff.time-off.destroy');

        Route::get('/holidays', [HolidayController::class, 'index'])->middleware('abs.can:abs_schedule.view')->name('holidays.index');
        Route::post('/holidays', [HolidayController::class, 'store'])->middleware('abs.can:abs_schedule.edit')->name('holidays.store');
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->middleware('abs.can:abs_schedule.edit')->name('holidays.destroy');

        Route::get('/appointments', [AppointmentController::class, 'index'])->middleware('abs.can:abs_appointment.view')->name('appointments.index');
        Route::get('/appointments/create', [AppointmentController::class, 'create'])->middleware('abs.can:abs_appointment.create')->name('appointments.create');
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])->middleware('abs.can:abs_appointment.view')->name('appointments.show');
        Route::post('/appointments/{appointment}/transition', [AppointmentController::class, 'transition'])->middleware('abs.can:abs_appointment.view')->name('appointments.transition');
        Route::put('/appointments/{appointment}/discount', [AppointmentController::class, 'discount'])->middleware('abs.can:abs_appointment.edit')->name('appointments.discount');
        Route::post('/appointments/{appointment}/documents', [AppointmentController::class, 'uploadDocument'])->middleware('abs.can:abs_appointment.edit')->name('appointments.documents.store');
        Route::delete('/appointments/{appointment}/documents/{document}', [AppointmentController::class, 'deleteDocument'])->middleware('abs.can:abs_appointment.edit')->name('appointments.documents.destroy');
        Route::put('/appointments/{appointment}/notes', [AppointmentController::class, 'updateNotes'])->middleware('abs.can:abs_appointment.edit')->name('appointments.notes');
        Route::get('/appointments/{appointment}/invoice', [AppointmentController::class, 'invoice'])->middleware('abs.can:abs_payment.view')->name('appointments.invoice');
        Route::get('/calendar', [CalendarController::class, 'index'])->middleware('abs.can:abs_appointment.view')->name('calendar');
        Route::get('/calendar/events', [CalendarController::class, 'events'])->middleware('abs.can:abs_appointment.view')->name('calendar.events');
        Route::post('/calendar/appointments/{appointment}/move', [CalendarController::class, 'move'])->middleware('abs.can:abs_appointment.reschedule')->name('calendar.move');

        Route::get('/payments', [PaymentController::class, 'index'])->middleware('abs.can:abs_payment.view')->name('payments.index');
        Route::post('/appointments/{appointment}/payments', [PaymentController::class, 'store'])->middleware('abs.can:abs_payment.record')->name('payments.store');
        Route::post('/payments/{payment}/refunds', [PaymentController::class, 'refund'])->middleware('abs.can:abs_payment.refund')->name('payments.refund');

        Route::get('/reports', [ReportController::class, 'index'])->middleware('abs.can:abs_report.view')->name('reports.index');
        Route::get('/reports/export/{type}', [ReportController::class, 'export'])->middleware('abs.can:abs_report.export')
            ->whereIn('type', ['appointments', 'payments', 'services', 'staff'])->name('reports.export');

        Route::get('/diagnostics', [DiagnosticsController::class, 'index'])->middleware('authorization:abs_diagnostic.view')->name('diagnostics');
    });
});
