<?php

use App\Http\Middleware\AuthenticateCampfire;
use App\Http\Middleware\IdentifyInstance;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'campfire.auth']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['campfire.auth' => AuthenticateCampfire::class]);
        $middleware->append(IdentifyInstance::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
