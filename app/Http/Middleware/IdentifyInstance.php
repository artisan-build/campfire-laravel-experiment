<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Name the instance that served the request.
 *
 * With several replicas behind one URL there is otherwise no way to tell whether two browsers are
 * talking to the same container, which is exactly what a horizontal-scaling claim rests on.
 */
final class IdentifyInstance
{
    public function handle(Request $request, Closure $next)
    {
        return tap($next($request), function ($response) {
            if (method_exists($response, 'header')) {
                $response->header('X-Campfire-Instance', gethostname());
            }
        });
    }
}
