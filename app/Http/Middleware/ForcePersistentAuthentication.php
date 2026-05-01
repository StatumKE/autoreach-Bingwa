<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePersistentAuthentication
{
    /**
     * Ensure Fortify authentication requests always request a persistent login.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('post')
            && $request->routeIs('login.store', 'register.store')
        ) {
            $request->merge(['remember' => true]);
        }

        return $next($request);
    }
}
