<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Lhdn\Data\SubmissionDocument;
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
            // Signing only ever fails over the issuer's key material, so the hold reason
            // stays certificate_expired; the specific cause (missing cert, key mismatch,
            // unreadable PEM) is preserved verbatim in last_submission_error.
            $document->forceFill(['last_submission_error' => SubmissionErrors::summary($e)])->save();
            $stateMachine->transition($document, DocumentStatus::Held, heldReason: HeldReason::CertificateExpired);

            return;
        } catch (\Throwable $e) {
            // Anything else (a builder bug, an OpenSSL failure, an encoding blow-up) is
            // not the merchant's fault, so the document is never marked invalid. Count
            // the attempt and rethrow so the queue retries; once the budget is spent,
            // park it in held so a human sees it instead of retrying forever.
            $attempts = $document->submission_attempts_count + 1;
            $document->forceFill([
                'submission_attempts_count' => $attempts,
                'last_submission_error' => ['kind' => 'prepare', 'message' => mb_substr($e->getMessage(), 0, 500), 'at' => now()->toIso8601String()],
            ])->save();
            if ($attempts < (int) config('lhdn.submission.max_attempts', 8)) {
                throw $e;
            }
            $stateMachine->transition($document, DocumentStatus::Held, 'prepare_failed', heldReason: HeldReason::LhdnUnavailable);

            return;
        }

        // LHDN's per-document limit applies to the base64 payload it receives, not the raw JSON.
        $max = (int) config('lhdn.submission.max_document_bytes', 307200);
        $size = SubmissionDocument::fromJson((string) $document->lhdn_internal_id, $signed->json)->wireSizeBytes();
        if ($size > $max) {
            $errors = [['code' => 'DOC_TOO_LARGE', 'message' => "Signed document is {$size} encoded bytes; the LHDN limit is {$max}."]];
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
