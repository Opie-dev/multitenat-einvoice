<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\HeldReason;

it('maps document types to LHDN codes and back', function () {
    expect(DocumentType::Invoice->lhdnCode())->toBe('01')
        ->and(DocumentType::CreditNote->lhdnCode())->toBe('02')
        ->and(DocumentType::DebitNote->lhdnCode())->toBe('03')
        ->and(DocumentType::RefundNote->lhdnCode())->toBe('04')
        ->and(DocumentType::SelfBilledInvoice->lhdnCode())->toBe('11')
        ->and(DocumentType::SelfBilledCreditNote->lhdnCode())->toBe('12')
        ->and(DocumentType::SelfBilledDebitNote->lhdnCode())->toBe('13')
        ->and(DocumentType::SelfBilledRefundNote->lhdnCode())->toBe('14')
        ->and(DocumentType::fromLhdnCode('12'))->toBe(DocumentType::SelfBilledCreditNote);
});

it('knows which types are self-billed and which need an original document', function () {
    expect(DocumentType::SelfBilledInvoice->isSelfBilled())->toBeTrue()
        ->and(DocumentType::Invoice->isSelfBilled())->toBeFalse()
        ->and(DocumentType::CreditNote->requiresOriginalRef())->toBeTrue()
        ->and(DocumentType::SelfBilledRefundNote->requiresOriginalRef())->toBeTrue()
        ->and(DocumentType::Invoice->requiresOriginalRef())->toBeFalse()
        ->and(DocumentType::SelfBilledInvoice->requiresOriginalRef())->toBeFalse();
});

it('has the exact status and held-reason values', function () {
    expect(array_map(fn ($c) => $c->value, DocumentStatus::cases()))->toBe([
        'draft', 'validated', 'held', 'queued', 'submitted', 'valid', 'invalid', 'cancelled', 'rejected', 'awaiting_consolidation', 'consolidated',
    ]);
    expect(array_map(fn ($c) => $c->value, HeldReason::cases()))->toBe([
        'issuer_not_active', 'certificate_expired', 'lhdn_credentials_invalid', 'lhdn_unavailable', 'einvoice_not_required',
    ]);
    expect(DocumentStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(DocumentStatus::Queued->isTerminal())->toBeFalse()
        ->and(HeldReason::IssuerNotActive->releasableOnIssuerActivation())->toBeTrue()
        ->and(HeldReason::LhdnUnavailable->releasableOnIssuerActivation())->toBeFalse();
});
