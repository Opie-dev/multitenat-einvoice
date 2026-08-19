<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Jobs\PrepareDocument;
use App\Lhdn\LhdnException;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->doc = Document::factory()->for($this->issuer)->valid()->create(['lhdn_status_at' => now()->subHour()]);
    app(TenantContext::class)->clear();
    $this->h = apiKeyHeaders($this->tenant, 'sandbox');
});

it('cancels a valid document within 72h via LHDN and audits it', function () {
    $this->withHeaders($this->h)->postJson("/v1/documents/{$this->doc->id}/cancel", ['reason' => 'Wrong buyer'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Wrong buyer');
    expect(collect(fakeLhdn()->calls())->last()['operation'])->toBe('cancel')->and(AuditLog::where('action', 'document.cancelled')->count())->toBe(1);
});

it('refuses outside the window, for non-valid documents, validates reason, and maps LHDN errors', function () {
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $old = Document::factory()->for($this->issuer)->valid()->create(['lhdn_status_at' => now()->subHours(80)]);
    app(TenantContext::class)->clear();
    $this->withHeaders($this->h)->postJson("/v1/documents/{$old->id}/cancel", ['reason' => 'late'])->assertStatus(409)->assertJsonPath('code', 'cancellation_window_closed');
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $queued = Document::factory()->for($this->issuer)->queued()->create();
    app(TenantContext::class)->clear();
    $this->withHeaders($this->h)->postJson("/v1/documents/{$queued->id}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
    $this->withHeaders($this->h)->postJson("/v1/documents/{$this->doc->id}/cancel", [])->assertStatus(422)->assertJsonFragment(['pointer' => '/reason']);
    fakeLhdn()->failNextWith(LhdnException::transient('down', 503), 'cancel');
    $this->withHeaders($this->h)->postJson("/v1/documents/{$this->doc->id}/cancel", ['reason' => 'x'])->assertStatus(503)->assertJsonPath('code', 'lhdn_unavailable');
    expect($this->doc->refresh()->status)->toBe(DocumentStatus::Valid);
});

it('is tenant and environment scoped and needs documents:write', function () {
    $this->withHeaders(apiKeyHeaders(Tenant::factory()->create(), 'sandbox'))->postJson("/v1/documents/{$this->doc->id}/cancel", ['reason' => 'x'])->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'production'))->postJson("/v1/documents/{$this->doc->id}/cancel", ['reason' => 'x'])->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'sandbox', ['read']))->postJson("/v1/documents/{$this->doc->id}/cancel", ['reason' => 'x'])->assertStatus(403);
});

it('resubmits an invalid document via POST /submit', function () {
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $invalid = Document::factory()->for($this->issuer)->create(['status' => 'invalid', 'lhdn_errors' => [['code' => 'X', 'message' => 'y']]]);
    app(TenantContext::class)->clear();
    Queue::fake();
    $this->withHeaders($this->h)->postJson("/v1/documents/{$invalid->id}/submit")->assertOk()->assertJsonPath('data.status', 'queued');
    Queue::assertPushed(PrepareDocument::class);
});
