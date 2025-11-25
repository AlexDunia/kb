<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Cors
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle pre-flight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 200);
        } else {
            $response = $next($request);
        }

        // Get the request origin
        $origin = $request->header('Origin');

        // Allow list of origins
        $allowedOrigins = [
            'http://localhost:3000',
            'http://localhost:5173',
            'http://localhost:5174',
            'http://localhost:5175',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:5174',
            'http://127.0.0.1:5175',
        ];

        // When using credentials, we must specify an exact origin (not "*")
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        // Without origin header, we can't use credentials
        else if ($origin) {
            // For other origins, check environment-specific config
            $envOrigins = explode(',', env('CORS_ALLOWED_ORIGINS', ''));
            if (in_array($origin, $envOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
            }
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-XSRF-TOKEN, X-CSRF-TOKEN, Accept');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}
