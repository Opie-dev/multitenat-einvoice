<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbility
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $actor = $this->context->actor();
        if ($actor === null || ! $actor->hasAbility($ability)) {
            throw ProblemException::forbidden("This credential lacks the '{$ability}' ability.");
        }

        return $next($request);
    }
}
