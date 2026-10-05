<?php

use App\Http\Middleware\AuthenticateCampfire;
use App\Http\Middleware\IdentifyInstance;
use App\Http\Middleware\RailsCsrf;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    // /broadcasting/auth runs behind Campfire's own session cookie, not a Laravel auth guard.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'campfire.auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['session_token', '_campfire_session']);
        $middleware->web(replace: [PreventRequestForgery::class => RailsCsrf::class]);
        $middleware->alias(['campfire.auth' => AuthenticateCampfire::class]);
        $middleware->append(IdentifyInstance::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $e, Request $r) {
            return response('Invalid authenticity token', 422);
        });
    })->create();
