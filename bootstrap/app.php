<?php

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Problem\ProblemResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.api' => AuthenticateApi::class,
            'tenant' => EnsureTenantContext::class,
            'ability' => EnsureAbility::class,
        ]);

        // Implicit route-model binding (SubstituteBindings) runs before route
        // middleware by default. Tenant-scoped models (BelongsToTenant) rely on
        // TenantContext being bound first, so authentication must run earlier.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AuthenticateApi::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('v1/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('v1/*') || $request->expectsJson()) {
                return ProblemResponse::fromThrowable($e, $request);
            }

            return null;
        });
    })->create();
