<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->has()) {
            throw ProblemException::badRequest('This endpoint requires a tenant. Service tokens must send X-Tenant-Id.', 'tenant_header_required');
        }

        return $next($request);
    }
}
