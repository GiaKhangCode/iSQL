<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );

        $middleware->alias([
            'role'       => \App\Http\Middleware\RoleMiddleware::class,
            'active'     => \App\Http\Middleware\EnsureActiveAccount::class,
            'permission' => \App\Http\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool =>
                $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $firstError = collect($e->errors())->flatten()->first();
                return response()->json([
                    'message' => $e->getMessage(),
                    'detail'  => $firstError ?: $e->getMessage(),
                    'errors'  => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'detail'  => $e->getMessage(),
                ], $e->getStatusCode());
            }
        });
    })->create();
