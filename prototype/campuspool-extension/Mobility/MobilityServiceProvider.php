<?php

namespace STS\Mobility;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MobilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $root = rtrim((string) (getenv('MOBILITY_EXTENSION_ROOT') ?: dirname(__DIR__)), '/');
        $this->app->bind(\STS\Mobility\Routing\RouteProvider::class, \STS\Mobility\Routing\OsrmRouteProvider::class);
        $this->app->bind(\STS\Mobility\Pickup\PathProvider::class, \STS\Mobility\Pickup\OsrmPathProvider::class);
        $this->mergeConfigFrom($root.'/config/routing.php', 'mobility_routing');
        $this->mergeConfigFrom($root.'/config/college.php', 'college');
    }

    public function boot(): void
    {
        // Laravel may sort throttling before the custom logged middleware.
        // Resolve the existing JWT guard explicitly so authenticated reporters
        // do not all consume the anonymous loopback-IP bucket.
        RateLimiter::for('mobility-trip-report-create', function (Request $request) {
            try {
                $user = $request->user('api');
            } catch (\Throwable) {
                $user = null;
            }

            return Limit::perHour((int) config('carpoolear.support_ticket_rate_limit_create_per_hour', 5))
                ->by($user ? 'mobility-report-user:'.$user->id : 'mobility-report-ip:'.$request->ip());
        });
        foreach (['send' => 60, 'block' => 30] as $kind => $limit) {
            RateLimiter::for('mobility-chat-'.$kind, function (Request $request) use ($kind, $limit) {
                // Numeric route limits can run before logged middleware and pool
                // every local tester into one IP bucket. Resolve the JWT guard.
                try { $user = $request->user('api'); } catch (\Throwable) { $user = null; }
                return Limit::perMinute($limit)->by('mobility-chat-'.$kind.'-'.($user ? 'user:'.$user->id : 'ip:'.$request->ip()));
            });
        }
        $root = rtrim((string) (getenv('MOBILITY_EXTENSION_ROOT') ?: dirname(__DIR__)), '/');
        $this->loadMigrationsFrom($root.'/migrations');
        \STS\Models\Passenger::observe(\STS\Mobility\Segments\CanonicalBookingObserver::class);
        \STS\Models\Trip::observe(\STS\Mobility\Segments\PublishedTripGuard::class);
        \STS\Models\TripPoint::saving([\STS\Mobility\Segments\PublishedTripGuard::class, 'point']);
        \STS\Models\TripPoint::deleting([\STS\Mobility\Segments\PublishedTripGuard::class, 'point']);
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(function (\STS\Mobility\Segments\SegmentException $error, $request) {
            return response()->json(['error' => ['code' => $error->machineCode]], $error->httpStatus);
        });
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(function (\STS\Mobility\Dispatch\DispatchException $error, $request) {
            return response()->json(['error' => ['code' => $error->machineCode]], $error->httpStatus);
        });
        $this->app['router']->pushMiddlewareToGroup('api', \STS\Mobility\Communications\CommunicationBoundary::class);
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(function (\STS\Mobility\Communications\CommunicationException $error) {
            return response()->json(['error' => ['code' => $error->machineCode]], $error->httpStatus)->header('Cache-Control', 'private, no-store, max-age=0');
        });
        $this->app['router']->pushMiddlewareToGroup('api', \STS\Mobility\Tracking\TrackingBoundary::class);
        $this->app['router']->pushMiddlewareToGroup('api', \STS\Mobility\Dispatch\DispatchBoundary::class);
        $this->app['router']->pushMiddlewareToGroup('api', \STS\Mobility\Journeys\PrivateScheduledBoundary::class);
        $this->app['router']->pushMiddlewareToGroup('api', \STS\Mobility\Routing\QuotedOfferGuard::class);
        $this->app['router']->pushMiddlewareToGroup('api', \STS\Mobility\Trust\CohortGuard::class);
        $this->app['router']->pushMiddlewareToGroup('api', \STS\Mobility\Recurrence\ProgramAdmissionGuard::class);
        $this->commands([\STS\Mobility\Journeys\EndpointRetentionCommand::class, \STS\Mobility\Recurrence\ProgramGenerationCommand::class, \STS\Mobility\Dispatch\DispatchWorkCommand::class]);
        if (filter_var(getenv('MOBILITY_RECURRENCE_SCHEDULER_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule): void {
                $schedule->command('mobility:generate-recurring --limit=20')->dailyAt('02:00')->timezone('Asia/Kolkata')->withoutOverlapping();
            });
        }
        $this->loadRoutesFrom($root.'/routes/mobility.php');
        $this->loadRoutesFrom($root.'/routes/college-auth.php');
        $this->loadRoutesFrom($root.'/routes/commute.php');
        $this->loadRoutesFrom($root.'/routes/driver-onboarding.php');
        $this->loadRoutesFrom($root.'/routes/tracking.php');
        $this->loadRoutesFrom($root.'/routes/communications.php');
        $this->loadRoutesFrom($root.'/routes/private-journeys.php');
        $this->loadRoutesFrom($root.'/routes/private-quotes.php');
        $this->loadRoutesFrom($root.'/routes/private-scheduled.php');
        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, fn ($schedule) => $schedule->command('mobility:redact-private-endpoints --limit=1000')->everyThirtyMinutes()->withoutOverlapping());
    }
}
