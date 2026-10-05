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
        /*
         * Authentication endpoints are intentionally stricter than normal API
         * traffic to reduce brute-force and account-enumeration abuse.
         */
        RateLimiter::for('auth-login', function (Request $request) {
            $email = mb_strtolower(
                trim((string) $request->input('email'))
            );

            return [
                Limit::perMinute(5)->by(
                    'login:' . $request->ip() . '|' . $email
                ),
            ];
        });

        RateLimiter::for('auth-register', function (Request $request) {
            return [
                Limit::perMinute(5)->by(
                    'register:' . $request->ip()
                ),
            ];
        });

        /*
         * Baseline limiter for authenticated API routes.
         * The key is user-first so multiple devices cannot bypass
         * the limit simply by switching IPs.
         */
        RateLimiter::for('api-user', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            return [
                Limit::perMinute(60)->by(
                    $userId !== null
                        ? 'user:' . $userId
                        : 'ip:' . $request->ip()
                ),
            ];
        });

        /*
         * Check-in gets a lower ceiling because it is a state-changing
         * operation and the ticket row is explicitly locked.
         */
        RateLimiter::for('api-check-in', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            return [
                Limit::perMinute(30)->by(
                    $userId !== null
                        ? 'check-in-user:' . $userId
                        : 'check-in-ip:' . $request->ip()
                ),
            ];
        });
    }
}