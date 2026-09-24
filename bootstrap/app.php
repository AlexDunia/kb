<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->group('api', [
            EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // Event-view tracking is intentionally public and writes no privileged
        // user state. Keeping it outside CSRF avoids an extra cookie round-trip
        // for every anonymous event visitor. Abuse is constrained by the
        // event-view limiter plus hourly server-side deduplication.
        $middleware->validateCsrfTokens(
            except: [
                'api/events/*/track-view',
                'api/payments/paystack/webhook',
            ]
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
