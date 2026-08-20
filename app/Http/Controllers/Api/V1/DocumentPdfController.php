<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Pdf\DocumentPdfGenerator;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentPdfController extends Controller
{
    public function show(Document $document, DocumentPdfGenerator $generator): BinaryFileResponse
    {
        if (! in_array($document->status, DocumentPdfGenerator::AVAILABLE_STATUSES, true) || $document->lhdn_uuid === null) {
            throw ProblemException::conflict('The PDF is available once LHDN validates the document.', 'pdf_not_available');
        }

        if ($generator->stale($document)) {
            $generator->generate($document);
        }

        return response()->file(Storage::disk('local')->path((string) $document->pdf_path), ['Content-Type' => 'application/pdf']);
    }
}
