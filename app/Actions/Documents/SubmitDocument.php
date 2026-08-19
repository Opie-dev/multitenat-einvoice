<?php

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Models\Document;

/** Manual release of a stored document into the submission queue. */
class SubmitDocument
{
    public function __construct(private readonly DocumentStateMachine $stateMachine) {}

    public function handle(Document $document): Document
    {
        if (! in_array($document->status, [DocumentStatus::Validated, DocumentStatus::Held], true)) {
            throw ProblemException::conflict("Document in status {$document->status->value} cannot be submitted.", 'invalid_transition');
        }

        $issuer = $document->issuer;
        if (! $issuer->einvoice_required) {
            throw ProblemException::conflict('This issuer is not required to e-invoice; the document is stored but will not be submitted.', 'einvoice_not_required');
        }
        if ($issuer->status !== IssuerStatus::Active) {
            throw ProblemException::conflict('The issuer is not active yet (TIN verification, LHDN authorisation and a valid certificate are required).', 'issuer_not_active');
        }

        $this->stateMachine->transition($document, DocumentStatus::Queued, 'manual_submit');

        return $document->refresh();
    }
}
