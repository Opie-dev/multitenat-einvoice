<?php

namespace App\Http\Controllers\Api\V1;

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
    public function index(): CursorPaginatedDataCollection
    {
        return ApiKeyData::collect(ApiKey::whereNull('revoked_at')->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50), CursorPaginatedDataCollection::class);
    }

    public function store(CreateApiKeyData $data, TenantContext $context): ApiKeyData
    {
        ['key' => $key, 'plaintext' => $plaintext] = ApiKey::generate(
            $context->tenant(),
            $data->name,
            $data->environment,
            $data->abilities,
        );

        return ApiKeyData::fromModel($key)->withPlaintext($plaintext)->wrap('data');
    }

    public function destroy(ApiKey $apiKey): Response
    {
        $apiKey->update(['revoked_at' => now()]);

        return response()->noContent();
    }
}
