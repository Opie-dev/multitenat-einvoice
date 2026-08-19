<?php

use App\Enums\Environment;
use App\Lhdn\CircuitBreaker;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionDocument;
use App\Lhdn\Http\HttpLhdnClient;
use App\Lhdn\Http\TokenProvider;
use App\Lhdn\LhdnCredentials;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['lhdn.driver' => 'http', 'lhdn.environments.sandbox.api_base' => 'https://lhdn.test', 'lhdn.environments.sandbox.identity_base' => 'https://lhdn.test']);
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create(['tin' => 'C1234567890']);
    $this->creds = new LhdnCredentials('cid', 'csecret', 'C1234567890', 'intermediary');
    $this->client = HttpLhdnClient::make(Environment::Sandbox, $this->creds);
});

it('fetches a token with onbehalfof, caches it, and records a redacted attempt', function () {
    Http::fake(['https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600, 'token_type' => 'Bearer'])]);
    $t = $this->client->token($this->issuer);
    expect($t->token)->toBe('abc');
    $this->client->token($this->issuer); // cached
    Http::assertSentCount(1);
    Http::assertSent(fn ($req) => $req->hasHeader('onbehalfof', 'C1234567890') && str_contains((string) $req->body(), 'grant_type=client_credentials') && str_contains((string) $req->body(), 'scope=InvoicingAPI'));
    $attempt = SubmissionAttempt::where('operation', 'token')->firstOrFail();
    expect(json_encode($attempt->request))->not->toContain('csecret')->and(json_encode($attempt->response))->not->toContain('abc')->and($attempt->http_status)->toBe(200);
});

it('submits documents and maps accepted/rejected', function () {
    Http::fake([
        'https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
        'https://lhdn.test/api/v1.0/documentsubmissions' => Http::response([
            'submissionUid' => 'SUB1',
            'acceptedDocuments' => [['uuid' => 'U1', 'invoiceCodeNumber' => 'D1']],
            'rejectedDocuments' => [['invoiceCodeNumber' => 'D2', 'error' => ['code' => 'CF321', 'message' => 'bad']]],
        ], 202),
    ]);
    $r = $this->client->submitDocuments($this->issuer, new SubmissionBatch([SubmissionDocument::fromJson('D1', '{"a":1}'), SubmissionDocument::fromJson('D2', '{"b":2}')]));
    expect($r->submissionUid)->toBe('SUB1')->and($r->acceptedUuidsByInternalId)->toBe(['D1' => 'U1'])->and($r->rejectedByInternalId['D2']['code'])->toBe('CF321');
    Http::assertSent(fn ($req) => $req->url() === 'https://lhdn.test/api/v1.0/documentsubmissions' && $req->hasHeader('Authorization', 'Bearer abc') && $req['documents'][0]['format'] === 'JSON');
    $attempt = SubmissionAttempt::where('operation', 'submit')->firstOrFail();
    expect(json_encode($attempt->request))->not->toContain(base64_encode('{"a":1}'))->and($attempt->submission_uid)->toBe('SUB1');
});

it('polls submissions and fetches document details', function () {
    Http::fake([
        'https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
        'https://lhdn.test/api/v1.0/documentsubmissions/SUB1*' => Http::response(['overallStatus' => 'partially valid', 'documentSummary' => [
            ['uuid' => 'U1', 'internalId' => 'D1', 'longId' => 'LONG1', 'status' => 'Valid'],
            ['uuid' => 'U2', 'internalId' => 'D2', 'longId' => null, 'status' => 'Invalid'],
        ]]),
        'https://lhdn.test/api/v1.0/documents/U2/details' => Http::response(['uuid' => 'U2', 'status' => 'Invalid', 'longId' => null, 'validationResults' => ['status' => 'Invalid', 'validationSteps' => [
            ['name' => 'Step1', 'status' => 'Valid'], ['name' => 'TaxStep', 'status' => 'Invalid', 'error' => ['code' => 'DS302', 'message' => 'tax mismatch', 'target' => 'TaxTotal']],
        ]]]),
    ]);
    $s = $this->client->getSubmission($this->issuer, 'SUB1');
    expect($s->isFinal())->toBeTrue()->and($s->documents[0]->longId)->toBe('LONG1')->and($s->documents[1]->status)->toBe('Invalid');
    $d = $this->client->getDocument($this->issuer, 'U2');
    expect($d->validationErrors)->toBe([['code' => 'DS302', 'message' => 'tax mismatch', 'target' => 'TaxTotal']]);
});

it('cancels and validates TINs', function () {
    Http::fake([
        'https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
        'https://lhdn.test/api/v1.0/documents/state/U1/state' => Http::response(['uuid' => 'U1', 'status' => 'Cancelled']),
        'https://lhdn.test/api/v1.0/taxpayer/validate/C1*' => Http::response(null, 200),
        'https://lhdn.test/api/v1.0/taxpayer/validate/C0*' => Http::response(['error' => 'not found'], 404),
    ]);
    $this->client->cancelDocument($this->issuer, 'U1', 'wrong buyer');
    Http::assertSent(fn ($req) => $req->method() === 'PUT' && $req['status'] === 'cancelled' && $req['reason'] === 'wrong buyer');
    expect($this->client->validateTin(Environment::Sandbox, 'C1111111111', 'BRN', '123', $this->issuer))->toBeTrue()
        ->and($this->client->validateTin(Environment::Sandbox, 'C0000000000', 'BRN', '123', $this->issuer))->toBeFalse();
});

it('classifies errors, forgets the token on auth failures, and trips the breaker on transient failures', function () {
    Http::fake([
        'https://lhdn.test/connect/token' => Http::sequence()->push(['access_token' => 'abc', 'expires_in' => 3600])->push(['access_token' => 'def', 'expires_in' => 3600]),
        'https://lhdn.test/api/v1.0/documentsubmissions' => Http::sequence()->push(['error' => 'forbidden'], 403)->push(['error' => 'down'], 503)->push(['error' => ['message' => 'bad request']], 400),
    ]);
    $batch = new SubmissionBatch([SubmissionDocument::fromJson('D1', '{}')]);
    expect(fn () => $this->client->submitDocuments($this->issuer, $batch))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Auth)->and($e->httpStatus)->toBe(403));
    expect(fn () => $this->client->submitDocuments($this->issuer, $batch))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Transient));
    Http::assertSentCount(4); // token, submit(403), token again (forgotten), submit(503)
    expect(fn () => $this->client->submitDocuments($this->issuer, $batch))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Terminal)->and($e->getMessage())->toContain('bad request'));
    expect(SubmissionAttempt::where('operation', 'submit')->where('error_kind', 'transient')->count())->toBe(1);
});

it('refuses calls while the breaker is open and respects the per-issuer rate budget', function () {
    config(['lhdn.circuit_breaker' => ['failure_threshold' => 1, 'cooldown_seconds' => 60], 'lhdn.rate_limits.validate_tin' => 1]);
    // Narrowed to the get_submission endpoint (rather than a blanket 'https://lhdn.test/*' wildcard): Http::fake()
    // never clears earlier registrations within a test, and matching returns the FIRST registered stub that
    // matches — so a broad wildcard registered here would keep shadowing the more specific taxpayer/* fake
    // registered later below, even though it's meant to apply only to this phase of the test.
    Http::fake(['https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]), 'https://lhdn.test/api/v1.0/documentsubmissions/*' => Http::response(['error' => 'down'], 502)]);
    expect(fn () => $this->client->getSubmission($this->issuer, 'X'))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Transient));
    expect(fn () => $this->client->getSubmission($this->issuer, 'X'))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Breaker));
    app(CircuitBreaker::class)->recordSuccess(Environment::Sandbox);
    Http::fake(['https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]), 'https://lhdn.test/api/v1.0/taxpayer/*' => Http::response(null, 200)]);
    $this->client->validateTin(Environment::Sandbox, 'C1', 'BRN', '1', $this->issuer);
    expect(fn () => $this->client->validateTin(Environment::Sandbox, 'C1', 'BRN', '1', $this->issuer))->toThrow(fn (LhdnException $e) => expect($e->httpStatus)->toBe(429));
});

it('does not double-count a token fetch failure as a second attempt or breaker failure', function () {
    config(['lhdn.circuit_breaker' => ['failure_threshold' => 2, 'cooldown_seconds' => 60]]);
    Http::fake(['https://lhdn.test/connect/token' => Http::response(['error' => 'down'], 503)]);

    expect(fn () => $this->client->getSubmission($this->issuer, 'X'))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Transient));
    expect(SubmissionAttempt::count())->toBe(1);
    $attempt = SubmissionAttempt::sole();
    expect($attempt->operation)->toBe('token')->and($attempt->error_kind)->toBe('transient');
    expect(app(CircuitBreaker::class)->isOpen(Environment::Sandbox))->toBeFalse();

    expect(fn () => $this->client->getSubmission($this->issuer, 'X'))->toThrow(LhdnException::class);
    expect(SubmissionAttempt::count())->toBe(2);
    expect(app(CircuitBreaker::class)->isOpen(Environment::Sandbox))->toBeTrue();
});

it('classifies an array error body without a message key by json-encoding it', function () {
    Http::fake([
        'https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
        'https://lhdn.test/api/v1.0/documents/state/U1/state' => Http::response(['error' => ['detail' => 'x']], 400),
    ]);

    expect(fn () => $this->client->cancelDocument($this->issuer, 'U1', 'reason'))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Terminal)->and($e->getMessage())->toContain('detail'));
});

it('does not record or count a limiter rejection in the token path', function () {
    config(['lhdn.rate_limits.token' => 1, 'lhdn.circuit_breaker' => ['failure_threshold' => 1, 'cooldown_seconds' => 60]]);
    Http::fake(['https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600])]);

    expect($this->client->token($this->issuer)->token)->toBe('abc');
    expect(SubmissionAttempt::count())->toBe(1);

    // Second fetch is refused by our own budget before any request is sent: no
    // attempt row to record, and nothing for the breaker to blame LHDN for.
    app(TokenProvider::class)->forget(Environment::Sandbox, $this->creds);
    expect(fn () => $this->client->token($this->issuer))
        ->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Transient)->and($e->httpStatus)->toBe(429));
    expect(SubmissionAttempt::count())->toBe(1)
        ->and(app(CircuitBreaker::class)->isOpen(Environment::Sandbox))->toBeFalse();
});

it('keeps the breaker closed for an LHDN 429 but opens it on a 5xx', function () {
    config(['lhdn.circuit_breaker' => ['failure_threshold' => 1, 'cooldown_seconds' => 60]]);
    Http::fake([
        'https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
        'https://lhdn.test/api/v1.0/documentsubmissions' => Http::sequence()->push(['error' => 'slow down'], 429)->push(['error' => 'down'], 503),
    ]);
    $batch = new SubmissionBatch([SubmissionDocument::fromJson('D1', '{}')]);

    expect(fn () => $this->client->submitDocuments($this->issuer, $batch))
        ->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Transient)->and($e->httpStatus)->toBe(429));
    expect(app(CircuitBreaker::class)->isOpen(Environment::Sandbox))->toBeFalse();

    expect(fn () => $this->client->submitDocuments($this->issuer, $batch))
        ->toThrow(fn (LhdnException $e) => expect($e->httpStatus)->toBe(503));
    expect(app(CircuitBreaker::class)->isOpen(Environment::Sandbox))->toBeTrue();
});
