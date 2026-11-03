<?php

namespace ME\Abs\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ME\Abs\Models\PaymentGatewaySetting;
use ME\Abs\Payments\GatewayManager;
use Throwable;

/**
 * Installation and deployment checks. Reports state only — never prints secret values.
 */
class Diagnostics
{
    public function __construct(private GatewayManager $gateways) {}

    /**
     * @return array<int, array{label: string, ok: bool, critical: bool, detail: string}>
     */
    public function requirements(): array
    {
        $checks = [];

        $checks[] = $this->check('PHP version', version_compare(PHP_VERSION, '8.3.0', '>='), true, PHP_VERSION.' (8.3+ required)');

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'json', 'intl', 'zip', 'fileinfo'] as $extension) {
            $checks[] = $this->check("PHP extension: {$extension}", extension_loaded($extension), $extension !== 'intl', extension_loaded($extension) ? 'loaded' : 'missing');
        }

        try {
            DB::connection()->getPdo();
            $checks[] = $this->check('Database connection', true, true, DB::connection()->getDriverName().' connected');
        } catch (Throwable) {
            $checks[] = $this->check('Database connection', false, true, 'cannot connect — check DB_* in .env');
        }

        $checks[] = $this->check('APP_KEY set', filled(config('app.key')), true, filled(config('app.key')) ? 'set' : 'run php artisan key:generate');
        $checks[] = $this->check('Application timezone is UTC', config('app.timezone') === 'UTC', true, 'config app.timezone = '.config('app.timezone'));
        $checks[] = $this->check('Writable storage', is_writable(storage_path()), true, storage_path());

        return $checks;
    }

    /**
     * @return array<int, array{label: string, ok: bool, critical: bool, detail: string}>
     */
    public function runtime(): array
    {
        $checks = $this->requirements();
        $production = app()->isProduction();

        $checks[] = $this->check('Debug mode off in production', ! ($production && config('app.debug')), $production, config('app.debug') ? 'APP_DEBUG=true' : 'APP_DEBUG=false');
        $checks[] = $this->check('Queue driver', config('queue.default') !== 'sync', false, 'QUEUE_CONNECTION='.config('queue.default').(config('queue.default') === 'sync' ? ' (mail is sent during the request)' : ''));
        $checks[] = $this->check('Mail transport', config('mail.default') !== 'log', false, 'mail.default = '.config('mail.default').(config('mail.default') === 'log' ? ' (configure SMTP on the Mail Configuration page)' : ''));

        $migrated = Schema::hasTable('abs_appointments') && Schema::hasTable('abs_payments');
        $checks[] = $this->check('Booking tables migrated', $migrated, true, $migrated ? 'ok' : 'run php artisan migrate');

        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $checks[] = $this->check('Failed queue jobs', $failedJobs === 0, false, "{$failedJobs} failed job(s)");

        if ($migrated) {
            $staleHolds = DB::table('abs_appointments')->where('status', 'awaiting_payment')
                ->where('hold_expires_at', '<', CarbonImmutable::now()->subMinutes(10))->count();
            $checks[] = $this->check('Scheduler running (holds expire)', $staleHolds === 0, false, $staleHolds ? "{$staleHolds} expired hold(s) not processed — is `php artisan schedule:run` in cron?" : 'no stale holds');
        }

        $enabled = PaymentGatewaySetting::query()->where('is_enabled', true)->get(['business_id', 'provider']);
        $checks[] = $this->check(
            'Online payment gateways',
            true,
            false,
            $enabled->isEmpty() && ! config('abs.gateway')
                ? 'none enabled (cash / pay later only) — Booking → Payment Gateways'
                : $enabled->pluck('provider')->unique()->sort()->join(', ').' enabled in '.$enabled->pluck('business_id')->unique()->count().' business(es) (live payments are not verified by this check)',
        );

        return $checks;
    }

    /**
     * @return array{label: string, ok: bool, critical: bool, detail: string}
     */
    private function check(string $label, bool $ok, bool $critical, string $detail): array
    {
        return compact('label', 'ok', 'critical', 'detail');
    }
}
