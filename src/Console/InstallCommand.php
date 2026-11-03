<?php

namespace ME\Abs\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ME\Abs\Database\Seeders\AbsClinicDemoSeeder;
use ME\Abs\Database\Seeders\AbsDemoSeeder;
use ME\Abs\Database\Seeders\AbsRolesSeeder;
use ME\Abs\Services\Diagnostics;

/**
 * Safe installer: checks the environment, runs pending migrations (never fresh/reset), creates the booking roles.
 * Re-running it is harmless.
 */
class InstallCommand extends Command
{
    protected $signature = 'abs:install
        {--demo : Also load generic demo data (only on an empty booking database)}
        {--clinic-demo : Also load doctor/clinic demo data (only on an empty booking database)}';

    protected $description = 'Check requirements, migrate and set up the Appointment Booking module';

    public function handle(Diagnostics $diagnostics): int
    {
        $failed = false;

        foreach ($diagnostics->requirements() as $check) {
            $this->line(sprintf('  [%s] %s — %s', $check['ok'] ? 'OK' : ($check['critical'] ? 'FAIL' : 'WARN'), $check['label'], $check['detail']));
            $failed = $failed || (! $check['ok'] && $check['critical']);
        }

        if ($failed) {
            $this->error('Fix the failed checks above, then run abs:install again.');

            return self::FAILURE;
        }

        $this->call('migrate', ['--no-interaction' => true, '--force' => $this->laravel->isProduction()]);
        $this->call('db:seed', ['--class' => AbsRolesSeeder::class, '--no-interaction' => true, '--force' => $this->laravel->isProduction()]);

        $seeder = match (true) {
            (bool) $this->option('clinic-demo') => AbsClinicDemoSeeder::class,
            (bool) $this->option('demo') => AbsDemoSeeder::class,
            default => null,
        };

        if ($seeder) {
            if (DB::table('abs_businesses')->exists()) {
                $this->warn('Demo data skipped: businesses already exist.');
            } else {
                $this->call('db:seed', ['--class' => $seeder, '--no-interaction' => true, '--force' => $this->laravel->isProduction()]);
            }
        }

        $this->info('Appointment Booking is installed. Remember to run the queue worker and the scheduler (see docs/DEPLOYMENT.md).');

        return self::SUCCESS;
    }
}
