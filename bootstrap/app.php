<?php

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\EnsureAbility;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Problem\ProblemResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listeners are registered explicitly (App\Providers\AppServiceProvider::boot())
    // for determinism; Laravel's automatic app/Listeners discovery is otherwise on
    // by default and would double-register the same listener, firing it twice.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // spec 3.3: every /v1 request is throttled per credential (see the
        // 'api' limiter in App\Providers\AppServiceProvider).
        $middleware->throttleApi();

        // Dashboard (Plan 5): shares Inertia props on every web request.
        $middleware->web(append: [HandleInertiaRequests::class]);

        $middleware->alias([
            'auth.api' => AuthenticateApi::class,
            'tenant' => EnsureTenantContext::class,
            'ability' => EnsureAbility::class,
            'idempotency' => IdempotencyKey::class,
        ]);

        // Implicit route-model binding (SubstituteBindings) runs before route
        // middleware by default. Tenant-scoped models (BelongsToTenant) rely on
        // TenantContext being bound first, so authentication must run earlier.
        // Final order: AuthenticateApi -> EnsureTenantContext -> SubstituteBindings
        // (EnsureAbility intentionally stays after SubstituteBindings, so a
        // cross-tenant lookup 404s via the scoped binding rather than 403ing).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AuthenticateApi::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureTenantContext::class,
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

        // Dashboard (Plan 5): 403/404/419/500 render Inertia error pages on
        // web routes only; /v1 keeps returning problem+json exactly as above.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            if ($request->is('v1/*') || $request->expectsJson()) {
                return $response;
            }

            $component = match ($response->getStatusCode()) {
                403 => 'Errors/Forbidden',
                404, 419 => 'Errors/NotFound',
                500 => config('app.debug') ? null : 'Errors/NotFound',
                default => null,
            };

            if ($component === null) {
                return $response;
            }

            return Inertia::render($component, ['status' => $response->getStatusCode()])
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
