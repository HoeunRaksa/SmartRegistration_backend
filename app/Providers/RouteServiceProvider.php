<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/home';

    public function boot(): void
    {
        // ✅ Add login rate limiter (does NOT change any endpoint)
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');
            // 30/min per (email + ip). Adjust if you want.
            return Limit::perMinute(30)->by(strtolower($email) . '|' . $request->ip());
        });

        $this->routes(function () {
            // ✅ Keep the same endpoint prefix: /api/...
            // ✅ Keep your current structure (no endpoint changes)
            Route::prefix('api')
                ->middleware('api')               // ✅ ensure api middleware group applies correctly
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
