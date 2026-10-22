<?php

namespace App\Http\Middleware;

use App\Services\PlatformSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyPlatformSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(PlatformSettingsService::class);
        $settings->applyRuntime();
        $general = $settings->group('general');
        view()->share('platformSettings', $general);

        return $next($request);
    }
}
