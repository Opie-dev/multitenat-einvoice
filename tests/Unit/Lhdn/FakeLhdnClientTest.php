<?php

use App\Enums\Environment;
use App\Enums\LhdnMode;
use App\Lhdn\CredentialsResolver;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionDocument;
use App\Lhdn\Fake\FakeLhdnClient;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
});

it('resolves the fake client from the factory in tests', function () {
    $client = app(LhdnClientFactory::class)->for($this->issuer);
    expect($client)->toBeInstanceOf(FakeLhdnClient::class)->and(fakeLhdn())->toBe($client);
});

it('builds a submission payload with hashes and base64 documents', function () {
    $doc = SubmissionDocument::fromJson('DOC1', '{"a":1}');
    $batch = new SubmissionBatch([$doc]);
    expect($doc->hashHex)->toBe(hash('sha256', '{"a":1}'))
        ->and($batch->toPayload()['documents'][0])->toBe(['format' => 'JSON', 'documentHash' => $doc->hashHex, 'codeNumber' => 'DOC1', 'document' => base64_encode('{"a":1}')])
        ->and($batch->sizeBytes())->toBe(7);
});

it('measures wire size as the base64 payload LHDN actually receives', function () {
    // Budgets are enforced against the encoded form, which is 4/3 of the raw JSON
    // rounded up to the next 4-byte group (padding included).
    foreach (['' => 0, '{}' => 4, '{"a":1}' => 12, '{"ab":12}' => 12, '{"abc":123}' => 16] as $json => $expected) {
        $document = SubmissionDocument::fromJson('DOC1', (string) $json);
        expect($document->wireSizeBytes())->toBe($expected)
            ->and($document->wireSizeBytes())->toBe(strlen(base64_encode((string) $json)));
    }

    $batch = new SubmissionBatch([SubmissionDocument::fromJson('D1', '{"a":1}'), SubmissionDocument::fromJson('D2', '{"abc":123}')]);
    expect($batch->sizeBytes())->toBe(18)->and($batch->wireSizeBytes())->toBe(28);
});

it('submits, polls to valid, and can be scripted to reject/invalidate/fail', function () {
    $fake = fakeLhdn();
    $fake->rejectDocument('D2', 'CF321', 'Schema error');
    $result = $fake->submitDocuments($this->issuer, new SubmissionBatch([SubmissionDocument::fromJson('D1', '{}'), SubmissionDocument::fromJson('D2', '{}')]));
    expect($result->submissionUid)->toStartWith('SUB-')
        ->and($result->acceptedUuidsByInternalId)->toHaveKey('D1')
        ->and($result->rejectedByInternalId['D2']['code'])->toBe('CF321');

    $fake->pollsUntilFinal(1);
    $first = $fake->getSubmission($this->issuer, $result->submissionUid);
    expect($first->isFinal())->toBeFalse();
    $uuid = $result->acceptedUuidsByInternalId['D1'];
    $fake->markInvalid($uuid, [['code' => 'DS302', 'message' => 'bad tax']]);
    $second = $fake->getSubmission($this->issuer, $result->submissionUid);
    expect($second->isFinal())->toBeTrue()->and($second->documents[0]->status)->toBe('Invalid')
        ->and($fake->getDocument($this->issuer, $uuid)->validationErrors[0]['code'])->toBe('DS302');

    $fake->failNextWith(LhdnException::transient('boom', 503));
    expect(fn () => $fake->token($this->issuer))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Transient));
    expect($fake->calls())->not->toBeEmpty();
});

it('validates TINs and records cancels', function () {
    $fake = fakeLhdn();
    $fake->invalidTin('C0000000000');
    expect($fake->validateTin(Environment::Sandbox, 'C1234567890', 'BRN', '123', $this->issuer))->toBeTrue()
        ->and($fake->validateTin(Environment::Sandbox, 'C0000000000', 'BRN', '123'))->toBeFalse();
    $fake->cancelDocument($this->issuer, 'UUID1', 'wrong buyer');
    expect(collect($fake->calls())->last()['operation'])->toBe('cancel');
});

it('resolves credentials per mode', function () {
    config(['lhdn.intermediary.sandbox' => ['client_id' => 'int-id', 'client_secret' => 'int-secret']]);
    $r = app(CredentialsResolver::class);
    $c = $r->for($this->issuer);
    expect($c->mode)->toBe('intermediary')->and($c->onBehalfOf)->toBe($this->issuer->tin)->and($c->clientId)->toBe('int-id');

    $own = Issuer::factory()->for($this->tenant)->active()->create(['lhdn_mode' => LhdnMode::OwnCredentials]);
    expect(fn () => $r->for($own))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Auth));
    $own->secret()->create(['lhdn_client_id' => 'own-id', 'lhdn_client_secret' => 'own-secret']);
    $c2 = $r->for($own->refresh());
    expect($c2->mode)->toBe('own')->and($c2->onBehalfOf)->toBeNull()->and($c2->clientSecret)->toBe('own-secret');
});
