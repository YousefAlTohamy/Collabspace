<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);

        RateLimiter::for('auth-register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
        ]);

        RateLimiter::for('auth-password', fn (Request $request) => [
            Limit::perMinute(3)->by($request->ip()),
        ]);

        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
        ]);

        // Password reset links must open the SPA, not the API.
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return config('app.frontend_url').'/reset-password?token='.$token.'&email='.urlencode($user->email);
        });
    }
}
