<?php

use App\Data\Requests\Documents\CreateDocumentBatchData;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Data\Requests\Documents\DocumentBuyerData;
use App\Enums\DocumentType;
use Illuminate\Validation\ValidationException;

function validDocumentPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'invoice',
        'issuer_id' => '01J0000000000000000000ISSU',
        'buyer' => ['general_public' => true],
        'currency' => 'MYR',
        'lines' => [[
            'classification_code' => '022',
            'description' => 'Widget',
            'quantity' => 2,
            'unit_code' => 'C62',
            'unit_price' => '10.50',
            'tax_type' => '02',
            'tax_rate' => 6,
        ]],
        'source' => ['system' => 'catalog', 'ref' => 'order-1'],
    ], $overrides);
}

it('accepts a valid payload and casts enums/collections', function () {
    $data = CreateDocumentData::validateAndCreate(validDocumentPayload());
    expect($data->type)->toBe(DocumentType::Invoice)
        ->and($data->lines)->toHaveCount(1)
        ->and($data->lines[0]->classification_code)->toBe('022')
        ->and($data->buyer->mode())->toBe('general_public')
        ->and($data->submit)->toBeTrue()
        ->and($data->consolidate)->toBeFalse();
});

it('rejects an unknown type, bad classification code, non-positive quantity and missing source', function () {
    try {
        CreateDocumentData::validateAndCreate(validDocumentPayload([
            'type' => 'receipt', 'lines' => [['classification_code' => '22', 'quantity' => 0]], 'source' => null,
        ]));
        $this->fail('expected validation exception');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('type', 'lines.0.classification_code', 'lines.0.quantity', 'source');
    }
});

it('requires a tax exemption reason when tax_type is E', function () {
    try {
        CreateDocumentData::validateAndCreate(validDocumentPayload(['lines' => [['tax_type' => 'E', 'tax_rate' => null]]]));
        $this->fail('expected validation exception');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('lines.0.tax_exemption_reason');
    }
});

it('requires exactly one of document_id / lhdn_uuid on original_document_ref', function () {
    try {
        CreateDocumentData::validateAndCreate(validDocumentPayload(['type' => 'credit_note', 'original_document_ref' => []]));
        $this->fail('expected validation exception');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('original_document_ref.document_id');
    }
});

it('accepts original_document_ref with only document_id or only lhdn_uuid, but not both', function () {
    $byDocumentId = CreateDocumentData::validateAndCreate(validDocumentPayload([
        'type' => 'credit_note', 'original_document_ref' => ['document_id' => '01J0000000000000000000DOC1'],
    ]));
    $byLhdnUuid = CreateDocumentData::validateAndCreate(validDocumentPayload([
        'type' => 'credit_note', 'original_document_ref' => ['lhdn_uuid' => 'ABCDEF1234567890'],
    ]));
    expect($byDocumentId->original_document_ref->document_id)->toBe('01J0000000000000000000DOC1')
        ->and($byLhdnUuid->original_document_ref->lhdn_uuid)->toBe('ABCDEF1234567890');

    try {
        CreateDocumentData::validateAndCreate(validDocumentPayload([
            'type' => 'credit_note',
            'original_document_ref' => ['document_id' => '01J0000000000000000000DOC1', 'lhdn_uuid' => 'ABCDEF1234567890'],
        ]));
        $this->fail('expected validation exception');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('original_document_ref.document_id');
    }
});

it('requires id_number whenever id_type is given on an inline buyer, and vice versa', function () {
    try {
        CreateDocumentData::validateAndCreate(validDocumentPayload([
            'buyer' => ['general_public' => false, 'name' => 'Ali', 'id_type' => 'NRIC'],
        ]));
        $this->fail('expected validation exception');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('buyer.id_number');
    }

    $data = CreateDocumentData::validateAndCreate(validDocumentPayload([
        'buyer' => ['general_public' => false, 'name' => 'Ali', 'id_type' => 'NRIC', 'id_number' => '900101015000'],
    ]));
    expect($data->buyer->id_number)->toBe('900101015000');
});

it('classifies buyer modes', function () {
    expect((new DocumentBuyerData(buyer_id: 'x'))->mode())->toBe('buyer_id')
        ->and((new DocumentBuyerData(general_public: true))->mode())->toBe('general_public')
        ->and((new DocumentBuyerData(name: 'Ali'))->mode())->toBe('inline')
        ->and((new DocumentBuyerData)->mode())->toBe('invalid')
        ->and((new DocumentBuyerData(buyer_id: 'x', general_public: true))->mode())->toBe('invalid');
});

it('computes a canonical payload hash independent of key order and the submit flag', function () {
    $a = CreateDocumentData::validateAndCreate(validDocumentPayload(['submit' => true]));
    $b = CreateDocumentData::validateAndCreate(array_reverse(validDocumentPayload(['submit' => false]), true));
    $c = CreateDocumentData::validateAndCreate(validDocumentPayload(['lines' => [['quantity' => 3]]]));
    expect($a->payloadHash())->toBe($b->payloadHash())->and($a->payloadHash())->not->toBe($c->payloadHash());
});

it('does not require exchange_rate for a nested MYR document inside a batch', function () {
    $batch = CreateDocumentBatchData::validateAndCreate([
        'documents' => [validDocumentPayload()],
    ]);
    expect($batch->documents)->toHaveCount(1)
        ->and($batch->documents[0]->currency)->toBe('MYR')
        ->and($batch->documents[0]->exchange_rate)->toBeNull();
});

it('requires exchange_rate for a nested non-MYR document inside a batch', function () {
    try {
        CreateDocumentBatchData::validateAndCreate([
            'documents' => [validDocumentPayload(['currency' => 'USD'])],
        ]);
        $this->fail('expected validation exception');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain('documents.0.exchange_rate');
    }
});
