<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => $request->is('api/*') ? null : rtrim((string) config('app.frontend_url'), '/').'/login');

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
        $exceptions->shouldRenderJsonWhen(fn (\Illuminate\Http\Request $request, \Throwable $exception) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function ($response) {
            if (request()->is('api/*')) {
                $status = $response->getStatusCode();
                $message = match ($status) {
                    401 => 'Unauthenticated.',
                    403 => 'You do not have permission to perform this action.',
                    404 => 'Resource not found.',
                    405 => 'Method not allowed.',
                    419 => 'Session expired. Please refresh CSRF protection and try again.',
                    429 => 'Too many requests. Please try again later.',
                    default => $status >= 500 ? 'Server error. Please try again later.' : null,
                };
                if ($message !== null) {
                    $response->setContent(json_encode(['message' => $message], JSON_THROW_ON_ERROR));
                    $response->headers->set('Content-Type', 'application/json');
                }
            }

            return $response;
        });
    })->create();
