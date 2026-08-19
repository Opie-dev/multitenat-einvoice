<?php

namespace App\Actions\Documents;

use App\Domain\Documents\CancellationWindowClosed;
use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Exceptions\ProblemException;
use App\Lhdn\LhdnClientFactory;
use App\Models\Document;
use App\Services\Audit\AuditLogger;

class CancelDocument
{
    public function __construct(private readonly LhdnClientFactory $clients, private readonly DocumentStateMachine $sm, private readonly AuditLogger $audit) {}

    public function handle(Document $document, string $reason): Document
    {
        if ($document->status !== DocumentStatus::Valid) {
            throw ProblemException::conflict("Only valid documents can be cancelled (current status: {$document->status->value}).", 'invalid_transition');
        }
        if (! $document->isCancellable()) {
            throw new CancellationWindowClosed;
        }
        if ($document->lhdn_uuid === null || $document->lhdn_uuid === '') {
            // A valid document without a UUID means our own state drifted from LHDN's;
            // sending an empty uuid would cancel nothing (or something else) silently.
            throw ProblemException::conflict('Document has no LHDN UUID to cancel.', 'lhdn_uuid_missing');
        }
        $issuer = $document->issuer;
        $this->clients->for($issuer)->cancelDocument($issuer, $document->lhdn_uuid, $reason);
        $this->sm->transition($document, DocumentStatus::Cancelled, $reason);
        $this->audit->record('document.cancelled', $document, ['reason' => $reason]);

        return $document->refresh();
    }
}
