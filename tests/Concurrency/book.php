<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use ME\Abs\Actions\AppointmentBookingAction;
use ME\Abs\Models\Business;
use ME\Abs\Services\Booking\BookingRequest;

/*
| Child process for ConcurrentBookingTest. Boots the host app, waits for a shared start time, then tries one booking.
| A query listener sleeps right after the conflict-check SELECT ... FOR UPDATE to widen the race window, so the
| test fails if the lock is missing. Prints "OK <id>" or "FAIL <message>".
|
| Args: basePath businessId serviceId staffId|any startUtc startAtMicrotime delayMs
*/

[$script, $basePath, $businessId, $serviceId, $staffId, $start, $goAt, $delayMs] = $argv;

require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::listen(function ($query) use ($delayMs) {
    if (str_contains($query->sql, 'abs_appointments') && str_contains($query->sql, 'for update')) {
        usleep((int) $delayMs * 1000);
    }
});

$business = Business::query()->findOrFail($businessId);
$request = new BookingRequest(
    $business,
    (int) $serviceId,
    $staffId === 'any' ? null : (int) $staffId,
    null,
    CarbonImmutable::parse($start, 'UTC'),
    'Concurrent '.getmypid(),
    'c'.getmypid().'@example.com',
);

while (microtime(true) < (float) $goAt) {
    usleep(500);
}

try {
    $appointment = $app->make(AppointmentBookingAction::class)->execute($request);
    echo 'OK '.$appointment->staff_id;
} catch (Throwable $e) {
    echo 'FAIL '.get_class($e).': '.$e->getMessage();
}
