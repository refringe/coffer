<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ShareStorageResolver;
use App\Models\User;
use App\Services\ShareStorageManager;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ShareStorageResolver::class, ShareStorageManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Throttle the file delivery endpoints per authenticated user (falling back to IP) so downloads and zip building
     * cannot be hammered.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('downloads', function (Request $request): Limit {
            $user = $request->user();

            $key = $user instanceof User
                ? 'user:'.$user->id
                : 'ip:'.($request->ip());

            return Limit::perMinute(120)->by($key);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    private function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // File responses honor the X-Sendfile-Type and X-Accel-Mapping request headers (set by the production web
        // server), replacing their body with an X-Accel-Redirect header so the web server transfers the file itself.
        BinaryFileResponse::trustXSendfileTypeHeader();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }
}
