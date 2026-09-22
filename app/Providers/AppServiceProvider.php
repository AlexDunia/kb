<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}
    public function boot(): void
    {
        if (config('app.env') === 'production') { URL::forceScheme('https'); }
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $frontendUrl = rtrim((string) config('app.frontend_url'), '/');
            return $frontendUrl . '/reset-password?' . http_build_query(['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);
        });
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('checkout-quote', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('checkout-initialize', function (Request $request) { $email=Str::lower((string) $request->input('customer.email','unknown')); return [Limit::perMinute(15)->by($request->ip()), Limit::perMinute(8)->by($email.'|'.$request->ip())]; });
        RateLimiter::for('checkout-verify', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('paystack-webhook', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
    }
}