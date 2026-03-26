<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DevCorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->headers->get('Origin');
        $allowed = $this->isAllowedOrigin($origin);

        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($allowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin');

        return $response;
    }

    private function isAllowedOrigin(?string $origin): bool
    {
        if (!$origin) {
            return false;
        }

        return preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin) === 1;
    }
}
