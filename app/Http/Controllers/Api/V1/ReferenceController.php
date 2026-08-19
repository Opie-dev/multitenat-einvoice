<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Resources\ReferenceCodeData;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\ReferenceCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ReferenceController extends Controller
{
    public function show(Request $request, string $set): JsonResponse|Response
    {
        if (! in_array($set, ReferenceCode::SETS, true)) {
            throw ProblemException::notFound("Unknown reference set '{$set}'.");
        }

        /** @var array{items: array<int, array<string,mixed>>, version: string} $payload */
        $payload = Cache::remember("reference:{$set}", 3600, function () use ($set): array {
            $rows = ReferenceCode::where('set', $set)->orderBy('code')->get(['code', 'description', 'extra', 'version']);
            $first = $rows->first();

            return [
                'items' => ReferenceCodeData::collect($rows)->toArray(),
                'version' => $first !== null ? $first->version : '',
            ];
        });

        $etag = '"'.sha1($set.'|'.$payload['version'].'|'.count($payload['items'])).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304)->setEtag($etag, false);
        }

        return response()
            ->json(['data' => $payload['items'], 'meta' => ['version' => $payload['version'], 'count' => count($payload['items'])]])
            ->setEtag($etag, false)
            ->setPublic()
            ->setMaxAge(3600);
    }
}
