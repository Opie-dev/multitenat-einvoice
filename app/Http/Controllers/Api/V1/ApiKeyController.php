<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ApiKeys\IssueApiKey;
use App\Actions\ApiKeys\RevokeApiKey;
use App\Data\Requests\CreateApiKeyData;
use App\Data\Resources\ApiKeyData;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
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

    public function store(CreateApiKeyData $data, IssueApiKey $action): ApiKeyData
    {
        ['key' => $key, 'plaintext' => $plaintext] = $action->handle($data);

        return ApiKeyData::fromModel($key)->withPlaintext($plaintext)->wrap('data');
    }

    public function destroy(ApiKey $apiKey, RevokeApiKey $action): Response
    {
        $action->handle($apiKey);

        return response()->noContent();
    }
}
