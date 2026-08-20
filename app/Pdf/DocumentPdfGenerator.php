<?php

namespace App\Pdf;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the visual invoice (Blade + dompdf) with the LHDN validation-link QR
 * code, and caches the result to the `local` disk. Generation is lazy: callers
 * check `stale()` before deciding whether to call `generate()` again.
 */
class DocumentPdfGenerator
{
    /**
     * Statuses a PDF is servable for. Single source of truth for
     * `DocumentPdfController::show()`'s availability guard and
     * `App\Domain\Documents\DocumentAbilities::for()`'s `can_pdf`.
     *
     * @var list<DocumentStatus>
     */
    public const AVAILABLE_STATUSES = [DocumentStatus::Valid, DocumentStatus::Cancelled, DocumentStatus::Rejected];

    public function generate(Document $document): string
    {
        $document->loadMissing('lines', 'issuer');

        $html = view('pdf.document', [
            'document' => $document,
            'issuer' => $document->issuer,
            'lines' => $document->lines,
            'typeLabel' => $this->typeLabel($document->type),
            'validationUrl' => $this->validationUrl($document),
            'qrDataUri' => $this->qrDataUri($document),
            'watermarkText' => $this->watermarkText($document->status),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();
        $pdfBytes = (string) $dompdf->output();

        $path = "documents/pdf/{$document->tenant_id}/{$document->id}.pdf";

        // Persist pdf_path (which bumps updated_at) *before* writing the file, so the
        // file's mtime is never earlier than the timestamp stale() compares it against.
        $document->forceFill(['pdf_path' => $path])->save();
        Storage::disk('local')->put($path, $pdfBytes);

        return $path;
    }

    public function stale(Document $document): bool
    {
        if ($document->pdf_path === null) {
            return true;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($document->pdf_path)) {
            return true;
        }

        return $disk->lastModified($document->pdf_path) < $document->updated_at->getTimestamp();
    }

    private function qrDataUri(Document $document): ?string
    {
        $url = $this->validationUrl($document);
        if ($url === null) {
            return null;
        }

        $result = (new Builder(
            writer: new PngWriter,
            data: $url,
            size: 180,
            margin: 8,
        ))->build();

        return $result->getDataUri();
    }

    /** The public MyInvois page for a validated document; mirrors DocumentData::validationUrl. */
    private function validationUrl(Document $document): ?string
    {
        if ($document->lhdn_uuid === null || $document->lhdn_long_id === null) {
            return null;
        }
        $portal = rtrim((string) config("lhdn.environments.{$document->environment->value}.portal_base"), '/');

        return $portal === '' ? null : "{$portal}/{$document->lhdn_uuid}/share/{$document->lhdn_long_id}";
    }

    private function watermarkText(DocumentStatus $status): ?string
    {
        return match ($status) {
            DocumentStatus::Cancelled => 'CANCELLED',
            DocumentStatus::Rejected => 'REJECTED',
            default => null,
        };
    }

    private function typeLabel(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Invoice => 'Invoice',
            DocumentType::CreditNote => 'Credit Note',
            DocumentType::DebitNote => 'Debit Note',
            DocumentType::RefundNote => 'Refund Note',
            DocumentType::SelfBilledInvoice => 'Self-Billed Invoice',
            DocumentType::SelfBilledCreditNote => 'Self-Billed Credit Note',
            DocumentType::SelfBilledDebitNote => 'Self-Billed Debit Note',
            DocumentType::SelfBilledRefundNote => 'Self-Billed Refund Note',
        };
    }
}
