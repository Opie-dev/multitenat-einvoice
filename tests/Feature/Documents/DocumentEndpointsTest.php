<?php

use App\Enums\Environment;
use App\Jobs\PrepareDocument;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

function apiDocPayload(Issuer $issuer, array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true],
        'lines' => [['classification_code' => '022', 'description' => 'Widget', 'quantity' => 2, 'unit_code' => 'C62', 'unit_price' => '10.50', 'tax_type' => '02', 'tax_rate' => 6]],
        'source' => ['system' => 'catalog', 'ref' => 'order-'.bin2hex(random_bytes(3))],
    ], $overrides);
}

beforeEach(function () {
    // The submission pipeline is covered end-to-end in tests/Feature/Lhdn/SubmissionPipelineTest;
    // here the queued -> prepared handoff would otherwise hold these certificate-less issuers' documents.
    Queue::fake([PrepareDocument::class]);
    $this->tenant = Tenant::factory()->create();
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create(); // sandbox
    $this->h = apiKeyHeaders($this->tenant, 'sandbox');
});

it('creates a document (201), replays (200) and conflicts (409)', function () {
    $payload = apiDocPayload($this->issuer);
    $r = $this->withHeaders($this->h)->postJson('/v1/documents', $payload)->assertCreated()
        ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.totals.total_payable', '22.26')
        ->assertJsonPath('data.lhdn_type_code', '01')->assertJsonPath('data.lines.0.total', '22.26')
        ->assertJsonPath('data.buyer.tin', 'EI00000000010');
    $id = $r->json('data.id');
    $this->withHeaders($this->h)->postJson('/v1/documents', $payload)->assertOk()->assertHeader('Idempotent-Replay', 'true')->assertJsonPath('data.id', $id);
    $payload['lines'][0]['quantity'] = 5;
    $this->withHeaders($this->h)->postJson('/v1/documents', $payload)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    expect(AuditLog::where('action', 'document.created')->count())->toBe(1);
});

it('returns 422 problem+json with pointers for invalid payloads and semantic errors', function () {
    $this->withHeaders($this->h)->postJson('/v1/documents', apiDocPayload($this->issuer, ['lines' => [['quantity' => 0]]]))
        ->assertStatus(422)->assertJsonFragment(['pointer' => '/lines/0/quantity']);
    $this->withHeaders($this->h)->postJson('/v1/documents', apiDocPayload($this->issuer, ['type' => 'credit_note']))
        ->assertStatus(422)->assertJsonFragment(['pointer' => '/original_document_ref']);
    $this->withHeaders($this->h)->postJson('/v1/documents', apiDocPayload($this->issuer, ['lines' => [['total' => '1.00']]]))
        ->assertStatus(422)->assertJsonFragment(['pointer' => '/lines/0/total']);
});

it('honours Idempotency-Key on create', function () {
    $h = $this->h + ['Idempotency-Key' => 'abc'];
    $first = $this->withHeaders($h)->postJson('/v1/documents', apiDocPayload($this->issuer))->assertCreated()->json('data.id');
    $this->withHeaders($h)->postJson('/v1/documents', apiDocPayload($this->issuer, ['source' => ['ref' => 'different']]))
        ->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
    expect(Document::withoutGlobalScopes()->count())->toBe(1)->and($first)->not->toBeNull();
});

it('creates a batch atomically with a shared group_id, and rejects the whole batch on one bad item', function () {
    $good = apiDocPayload($this->issuer);
    $bad = apiDocPayload($this->issuer, ['type' => 'credit_note']);
    $this->withHeaders($this->h)->postJson('/v1/documents/batch', ['documents' => [$good, $bad]])
        ->assertStatus(422)->assertJsonFragment(['pointer' => '/documents/1/original_document_ref']);
    expect(Document::withoutGlobalScopes()->count())->toBe(0);

    $r = $this->withHeaders($this->h)->postJson('/v1/documents/batch', ['documents' => [apiDocPayload($this->issuer), apiDocPayload($this->issuer)]])
        ->assertCreated()->assertJsonCount(2, 'data')->assertJsonPath('meta.count', 2);
    $group = $r->json('meta.group_id');
    expect($r->json('data.0.group_id'))->toBe($group)->and($r->json('data.1.group_id'))->toBe($group);
    $this->withHeaders($this->h)->getJson("/v1/documents?group_id={$group}")->assertOk()->assertJsonCount(2, 'data');
});

it('lists with filters, shows, and lists events; other tenants and environments get 404/empty', function () {
    // Document::create() fills tenant_id from the bound TenantContext (BelongsToTenant),
    // not from a factory ->for() relation, so bind it around this direct factory create.
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $doc = Document::factory()->for($this->issuer)->queued()->create(['environment' => Environment::Sandbox]);
    app(TenantContext::class)->clear();
    $this->withHeaders($this->h)->getJson('/v1/documents?status=queued')->assertOk()->assertJsonCount(1, 'data');
    $this->withHeaders($this->h)->getJson('/v1/documents?status=nope')->assertStatus(422);
    $this->withHeaders($this->h)->getJson("/v1/documents/{$doc->id}")->assertOk()->assertJsonPath('data.id', $doc->id);
    $this->withHeaders($this->h)->getJson("/v1/documents/{$doc->id}/events")->assertOk();

    $this->withHeaders(apiKeyHeaders(Tenant::factory()->create(), 'sandbox'))->getJson("/v1/documents/{$doc->id}")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'production'))->getJson("/v1/documents/{$doc->id}")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'production'))->getJson('/v1/documents')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects an inverted issue date range', function () {
    $this->withHeaders($this->h)->getJson('/v1/documents?issue_date_from=2026-08-10&issue_date_to=2026-08-01')
        ->assertStatus(422)->assertJsonFragment(['pointer' => '/issue_date_to']);
    $this->withHeaders($this->h)->getJson('/v1/documents?issue_date_from=2026-08-01&issue_date_to=2026-08-10')->assertOk();
});

it('submits a validated document and reports 409 for wrong states', function () {
    $id = $this->withHeaders($this->h)->postJson('/v1/documents', apiDocPayload($this->issuer, ['submit' => false]))->assertCreated()->assertJsonPath('data.status', 'validated')->json('data.id');
    $this->withHeaders($this->h)->postJson("/v1/documents/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'queued');
    $this->withHeaders($this->h)->postJson("/v1/documents/{$id}/submit")->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    expect(AuditLog::where('action', 'document.submitted')->count())->toBe(1);
});

it('enforces abilities', function () {
    $this->withHeaders(apiKeyHeaders($this->tenant, 'sandbox', ['read']))->postJson('/v1/documents', apiDocPayload($this->issuer))->assertStatus(403);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'sandbox', ['documents:write']))->getJson('/v1/documents')->assertStatus(403);
});
