<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // For API requests, we'll just return null
        // This will cause a 401 Unauthorized response instead of a redirect
        if ($request->is('api/*')) {
            return null;
        }

        // For web requests, redirect to the login page
        // In production, update this to your frontend login URL
        return $request->expectsJson() ? null : env('FRONTEND_URL', 'http://localhost:3000') . '/login';
    }
}
