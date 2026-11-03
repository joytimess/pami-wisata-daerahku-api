<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ticket-scans', fn (Request $request) => [
            Limit::perMinute(120)->by('ticket-actor:'.$request->user()?->guid),
            Limit::perMinute(240)->by('ticket-ip:'.$request->ip()),
        ]);

        RateLimiter::for('verification-otp', function (Request $request) {
            $email = is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : '';

            return [
                Limit::perMinute(10)->by('otp-ip:'.$request->ip()),
                Limit::perMinute(10)->by('otp-email:'.hash('sha256', $email)),
            ];
        });
    }
}
