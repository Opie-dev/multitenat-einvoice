<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\CreateDocumentBatch;
use App\Data\Requests\Documents\CreateDocumentBatchData;
use App\Data\Resources\DocumentData;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

class DocumentBatchController extends Controller
{
    public function store(CreateDocumentBatchData $batch, CreateDocumentBatch $create, AuditLogger $audit): JsonResponse
    {
        $result = $create->handle($batch);
        $audit->record('document.batch_created', null, ['group_id' => $result['group_id'], 'count' => count($result['documents'])]);

        return response()->json([
            'data' => array_map(fn ($r) => DocumentData::fromModel($r->document)->toArray(), $result['documents']),
            'meta' => ['group_id' => $result['group_id'], 'count' => count($result['documents'])],
        ], 201);
    }
}
