<?php

namespace App\Actions\ApiKeys;

use App\Exceptions\ProblemException;
use App\Models\ApiKey;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;

class RevokeApiKey
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function handle(ApiKey $apiKey): void
    {
        // 404 rather than 403: an out-of-environment key must not be provable.
        if ($this->context->isApiKeyActor() && $apiKey->environment !== $this->context->environment()) {
            throw ProblemException::notFound();
        }

        $apiKey->update(['revoked_at' => now()]);

        $this->audit->record('api_key.revoked', $apiKey);
    }
}
