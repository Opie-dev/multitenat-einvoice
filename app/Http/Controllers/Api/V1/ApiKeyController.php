<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\CreateApiKeyData;
use App\Data\Resources\ApiKeyData;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Spatie\LaravelData\CursorPaginatedDataCollection;

class ApiKeyController extends Controller
{
    /** @return CursorPaginatedDataCollection<int, ApiKeyData> */
    public function index(TenantContext $context): CursorPaginatedDataCollection
    {
        $query = ApiKey::whereNull('revoked_at')->orderByDesc('created_at')->orderByDesc('id');
        if ($context->isApiKeyActor()) {
            $query->where('environment', $context->environment());
        }

        return ApiKeyData::collect($query->cursorPaginate(50), CursorPaginatedDataCollection::class);
    }

    public function store(CreateApiKeyData $data, TenantContext $context, AuditLogger $audit): ApiKeyData
    {
        if ($context->isApiKeyActor() && $data->environment !== $context->environment()) {
            throw ProblemException::forbidden('An API key can only create keys for its own environment.');
        }

        ['key' => $key, 'plaintext' => $plaintext] = ApiKey::generate(
            $context->tenant(),
            $data->name,
            $data->environment,
            $data->abilities,
        );

        $audit->record('api_key.created', $key, [
            'name' => $key->name,
            'environment' => $key->environment->value,
            'abilities' => $key->abilities,
        ]);

        return ApiKeyData::fromModel($key)->withPlaintext($plaintext)->wrap('data');
    }

    public function destroy(ApiKey $apiKey, TenantContext $context, AuditLogger $audit): Response
    {
        // 404 rather than 403: an out-of-environment key must not be provable.
        if ($context->isApiKeyActor() && $apiKey->environment !== $context->environment()) {
            throw ProblemException::notFound();
        }

        $apiKey->update(['revoked_at' => now()]);

        $audit->record('api_key.revoked', $apiKey);

        return response()->noContent();
    }
}
