<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Lhdn\LhdnException;
use App\Lhdn\Pipeline\SubmissionErrors;
use App\Lhdn\Signing\DocumentSigner;
use App\Lhdn\Signing\SigningMaterial;
use App\Lhdn\Ubl\UblDocumentBuilder;
use App\Models\Document;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Turns a queued document into a signed UBL payload, then nudges the issuer's
 * submission batch. Anything the issuer has to fix first parks the document in
 * `held` so it can be released once onboarding completes.
 */
class PrepareDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public readonly string $documentId)
    {
        $this->captureTenantContext();
    }

    public function handle(UblDocumentBuilder $builder, DocumentSigner $signer, DocumentStateMachine $stateMachine): void
    {
        $document = Document::query()->with(['lines', 'issuer.secret', 'originalDocument'])->find($this->documentId);
        if ($document === null || $document->status !== DocumentStatus::Queued) {
            return; // already prepared, held or cancelled by something else
        }

        $issuer = $document->issuer;
        if ($issuer->status !== IssuerStatus::Active) {
            $stateMachine->transition($document, DocumentStatus::Held, heldReason: HeldReason::IssuerNotActive);

            return;
        }
        if (! $issuer->hasValidCertificate() || $issuer->secret === null || ! $issuer->secret->hasCertificate()) {
            $stateMachine->transition($document, DocumentStatus::Held, heldReason: HeldReason::CertificateExpired);

            return;
        }

        try {
            $signed = $signer->sign($builder->build($document), SigningMaterial::fromSecret($issuer->secret));
        } catch (LhdnException $e) {
            $document->forceFill(['last_submission_error' => SubmissionErrors::summary($e)])->save();
            $stateMachine->transition($document, DocumentStatus::Held, heldReason: HeldReason::CertificateExpired);

            return;
        }

        $max = (int) config('lhdn.submission.max_document_bytes', 307200);
        $size = strlen($signed->json);
        if ($size > $max) {
            $errors = [['code' => 'DOC_TOO_LARGE', 'message' => "Signed document is {$size} bytes; the LHDN limit is {$max}."]];
            $document->forceFill(['lhdn_errors' => $errors])->save();
            $stateMachine->transition($document, DocumentStatus::Invalid, 'document_too_large', ['errors' => $errors]);

            return;
        }

        $document->forceFill([
            'ubl_json' => $signed->json,
            'signed_payload_hash' => $signed->hashHex,
            'lhdn_internal_id' => $document->lhdn_internal_id ?? $document->id,
        ])->save();

        SubmitDocuments::dispatch($issuer->id);
    }
}
