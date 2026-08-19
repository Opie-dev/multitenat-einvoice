<?php

namespace App\Http\Middleware;

use App\Auth\CredentialResolver;
use App\Enums\Environment;
use App\Exceptions\ProblemException;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    public function __construct(
        private readonly CredentialResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            throw ProblemException::unauthenticated('Missing bearer token.');
        }

        $credential = $this->resolver->resolve($bearer);
        if ($credential === null) {
            throw ProblemException::unauthenticated('Invalid or revoked credential.');
        }

        $tenant = $credential->tenant;
        $environment = $credential->environment;

        if ($tenant === null) { // service token: tenant + environment come from headers
            $tenantId = $request->header('X-Tenant-Id');
            if ($tenantId !== null && $tenantId !== '') {
                $tenant = Tenant::query()->find($tenantId)
                    ?? throw new ProblemException(404, 'Not Found', 'Tenant not found.', 'tenant_not_found');
            }
            $envHeader = $request->header('X-Environment', Environment::Production->value);
            $environment = Environment::tryFrom((string) $envHeader)
                ?? throw ProblemException::badRequest('X-Environment must be "sandbox" or "production".', 'invalid_environment');
        }

        $this->context->bind($tenant, $credential->actor, $environment ?? Environment::Production);
        $credential->touch();

        return $next($request);
    }
}
