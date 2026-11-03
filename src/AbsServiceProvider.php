<?php

namespace ME\Abs;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use ME\Abs\Console\ExpireHoldsCommand;
use ME\Abs\Console\InstallCommand;
use ME\Abs\Console\SendRemindersCommand;
use ME\Abs\Http\Middleware\BusinessPermission;
use ME\Abs\Http\Middleware\KeepCustomersOutOfAdmin;
use ME\Abs\Http\Middleware\RequireBusiness;
use ME\Abs\Http\Middleware\SecurityHeaders;
use ME\Abs\Listeners\RecordNotificationDelivery;
use ME\Abs\Livewire\AppointmentTable;
use ME\Abs\Livewire\BookingWizard;
use ME\Abs\Livewire\ManualBooking;
use ME\Abs\Livewire\RescheduleForm;
use ME\Abs\Livewire\ScheduleEditor;
use ME\Abs\Payments\GatewayManager;
use ME\Abs\Services\Notifications\AppointmentNotifier;
use ME\Abs\Support\BusinessAccess;
use ME\Abs\Support\CurrentBusiness;
use ME\Services\MediaRegistry;

class AbsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/config.php', 'abs');
        $this->mergeConfigFrom(__DIR__.'/Config/permission.php', 'permissions');

        // Per request: membership cache and the session-bound business must never leak between requests/jobs.
        $this->app->scoped(BusinessAccess::class);
        $this->app->scoped(CurrentBusiness::class, fn ($app) => new CurrentBusiness($app->make(BusinessAccess::class), $app['session.store']));
        $this->app->singleton(GatewayManager::class);
        // One notifier per request/job so muted() applies to every action that sends.
        $this->app->scoped(AppointmentNotifier::class);
    }

    public function boot(): void
    {
        // After every provider booted: the route files use metheme helpers (me_prefix()) that metheme loads in its boot().
        $this->app->booted(fn () => $this->loadRoutesFrom(__DIR__.'/routes/web.php'));
        $this->loadViewsFrom(__DIR__.'/resources/views', 'abs');
        Blade::anonymousComponentPath(__DIR__.'/resources/views/components', 'abs');
        $this->loadTranslationsFrom(__DIR__.'/resources/lang', 'abs');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Admin home after login (metheme has no dashboard of its own); an explicit METHEME_HOME_ROUTE wins.
        if (! config('me_settings.home_route')) {
            config(['me_settings.home_route' => 'abs.dashboard']);
        }

        // Sidebar entries are a numeric array — must array_merge, not mergeConfigFrom
        if (file_exists($sidebar = __DIR__.'/Config/sidebar.php')) {
            Config::set('sidebar', array_merge(config('sidebar', []), require $sidebar));
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('abs.business', RequireBusiness::class);
        $router->aliasMiddleware('abs.can', BusinessPermission::class);
        $router->aliasMiddleware('abs.headers', SecurityHeaders::class);
        // Guests on customer pages go to the customer login (admin pages keep metheme's /login);
        // signed-in visitors of the customer login/register go to their panel.
        $account = trim((string) config('abs.account_path', 'account'), '/');
        Authenticate::redirectUsing(fn (Request $request) => $request->is($account, $account.'/*')
            ? route('abs.account.login')
            : (Route::has('login') ? route('login') : null));
        RedirectIfAuthenticated::redirectUsing(fn (Request $request) => $request->is($account.'/*')
            ? route('abs.account.dashboard')
            : (function_exists('me_home_url') ? me_home_url() : '/'));

        // Customers never see the admin panel, however they logged in.
        $router->pushMiddlewareToGroup('web', KeepCustomersOutOfAdmin::class);

        $this->registerLivewire();
        $this->registerRateLimiters();
        $this->registerConsole();

        Event::listen(NotificationSent::class, [RecordNotificationDelivery::class, 'sent']);
        Event::listen(NotificationFailed::class, [RecordNotificationDelivery::class, 'failed']);

        if (class_exists(MediaRegistry::class)) {
            MediaRegistry::owner(Models\Business::class, 'Booking business');
        }
    }

    private function registerLivewire(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        Livewire::component('abs-booking-wizard', BookingWizard::class);
        Livewire::component('abs-appointment-table', AppointmentTable::class);
        Livewire::component('abs-manual-booking', ManualBooking::class);
        Livewire::component('abs-schedule-editor', ScheduleEditor::class);
        Livewire::component('abs-reschedule-form', RescheduleForm::class);
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('abs-slots', fn (Request $request) => Limit::perMinute(config('abs.rate_limits.slots', 60))->by($request->ip()));
        RateLimiter::for('abs-booking', fn (Request $request) => Limit::perMinute(config('abs.rate_limits.booking', 10))->by($request->ip()));
        RateLimiter::for('abs-webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));
    }

    private function registerConsole(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SendRemindersCommand::class, ExpireHoldsCommand::class, InstallCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('abs:expire-holds')->everyMinute()->withoutOverlapping()->onOneServer();
            $schedule->command('abs:send-reminders')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
        });
    }
}
