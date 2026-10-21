<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Sau này khi bạn tạo RoleMiddleware và EnsureActiveAccount,
        // bạn sẽ đăng ký alias tại đây:
        /*
        $middleware->alias([
            'role'   => \App\Http\Middleware\RoleMiddleware::class,
            'active' => \App\Http\Middleware\EnsureActiveAccount::class,
        ]);
        */
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, $e) {
            return $request->is('api/*');
        });
    })->create();
