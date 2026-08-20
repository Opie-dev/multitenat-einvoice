<?php

namespace App\Actions\ApiKeys;

use App\Data\Requests\CreateApiKeyData;
use App\Exceptions\ProblemException;
use App\Models\ApiKey;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;

class IssueApiKey
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** @return array{key: ApiKey, plaintext: string} */
    public function handle(CreateApiKeyData $data): array
    {
        if ($this->context->isApiKeyActor() && $data->environment !== $this->context->environment()) {
            throw ProblemException::forbidden('An API key can only create keys for its own environment.');
        }

        ['key' => $key, 'plaintext' => $plaintext] = ApiKey::generate(
            $this->context->tenant(),
            $data->name,
            $data->environment,
            $data->abilities,
        );

        $this->audit->record('api_key.created', $key, [
            'name' => $key->name,
            'environment' => $key->environment->value,
            'abilities' => $key->abilities,
        ]);

        return ['key' => $key, 'plaintext' => $plaintext];
    }
}
