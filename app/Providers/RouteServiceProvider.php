<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;

/** Routes are registered exclusively in bootstrap/app.php. */
class RouteServiceProvider extends ServiceProvider
{
    // Retained for legacy middleware references.
    public const HOME = '/home';
}
