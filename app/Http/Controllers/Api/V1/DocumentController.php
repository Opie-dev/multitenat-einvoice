<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\CancelDocument;
use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\SubmitDocument;
use App\Data\Requests\Documents\CancelDocumentData;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Data\Requests\Documents\DocumentFilterData;
use App\Data\Resources\DocumentData;
use App\Data\Resources\DocumentEventData;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\DataCollection;

class DocumentController extends Controller
{
    /** @return CursorPaginatedDataCollection<int, DocumentData> */
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $f = DocumentFilterData::validateAndCreate($request->query());
        $q = Document::forCurrentEnvironment()->with('lines');
        $q->when($f->status, fn ($q) => $q->where('status', $f->status));
        $q->when($f->type, fn ($q) => $q->where('type', $f->type));
        $q->when($f->issuer_id, fn ($q) => $q->where('issuer_id', $f->issuer_id));
        $q->when($f->group_id, fn ($q) => $q->where('group_id', $f->group_id));
        $q->when($f->source_system, fn ($q) => $q->where('source_system', $f->source_system));
        $q->when($f->source_ref, fn ($q) => $q->where('source_ref', $f->source_ref));
        // issue_date is a DATE column, so plain comparisons stay sargable (whereDate() wraps it in a function).
        $q->when($f->issue_date_from, fn ($q) => $q->where('issue_date', '>=', $f->issue_date_from));
        $q->when($f->issue_date_to, fn ($q) => $q->where('issue_date', '<=', $f->issue_date_to));

        return DocumentData::collect($q->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50), CursorPaginatedDataCollection::class);
    }

    public function store(CreateDocumentData $data, CreateDocument $create, AuditLogger $audit): JsonResponse
    {
        $result = $create->handle($data);
        $doc = $result->document;
        if ($result->replayed) {
            return response()->json(['data' => DocumentData::fromModel($doc)->toArray()], 200, ['Idempotent-Replay' => 'true']);
        }
        $audit->record('document.created', $doc, [
            'type' => $doc->type->value, 'source' => ['system' => $doc->source_system, 'ref' => $doc->source_ref],
            'total_payable' => $doc->total_payable, 'status' => $doc->status->value,
        ]);

        return response()->json(['data' => DocumentData::fromModel($doc)->toArray()], 201);
    }

    public function show(Document $document): DocumentData
    {
        return DocumentData::fromModel($document)->wrap('data');
    }

    public function submit(Document $document, SubmitDocument $submit, AuditLogger $audit): JsonResponse
    {
        $doc = $submit->handle($document);
        $audit->record('document.submitted', $doc, ['status' => $doc->status->value]);

        return response()->json(['data' => DocumentData::fromModel($doc)->toArray()], 200);
    }

    public function cancel(CancelDocumentData $data, Document $document, CancelDocument $cancel): JsonResponse
    {
        $doc = $cancel->handle($document, $data->reason);

        return response()->json(['data' => DocumentData::fromModel($doc)->toArray()]);
    }

    /** @return DataCollection<int, DocumentEventData> */
    public function events(Document $document): DataCollection
    {
        return DocumentEventData::collect($document->events()->get(), DataCollection::class)->wrap('data');
    }
}
