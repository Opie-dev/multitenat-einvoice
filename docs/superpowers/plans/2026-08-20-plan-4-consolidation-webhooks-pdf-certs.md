# E-Invoice Engine — Plan 4: Consolidation, Webhooks, PDF/QR, Certificate Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the post-`valid` lifecycle (status refresh, buyer rejection, duplicate-submission recovery), deliver signed webhooks for every document/issuer/certificate event, generate the monthly consolidated B2C invoices, render PDF invoices with the LHDN QR link, and run the certificate expiry monitor — completing spec v1 for the API.

**Architecture:** Webhook delivery is a custom tenant-aware queued job (`DeliverWebhook`) with HMAC-SHA256 signing, its own backoff curve (≤24 h) and a `webhook_deliveries` log — replacing the spec's `spatie/laravel-webhook-server` (whose queued job is not tenant-aware; spec amended in Task 7). Events fan out from the existing after-commit `DocumentTransitioned` plus new `IssuerStatusChanged` / certificate events, all listeners registered explicitly. Consolidation, certificate monitoring, status refresh and attempts pruning are scheduled artisan commands that iterate tenants with a system actor (same pattern as `einvoice:lhdn-dispatch`). PDF rendering is lazy (generated on first GET, cached to storage, invalidated on cancel).

**Tech Stack:** Laravel 12, spatie/laravel-data, brick/math, `dompdf/dompdf` + `endroid/qr-code` (new deps), Pest, Larastan 8.

**Spec:** `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` §5.6, §7.2, §7.3, §7.4, §8 (webhooks, pdf, redeliver rows). Roadmap row 4 + "Plan 3 outcome → Backlog carried into Plan 4+".

## Global Constraints

- Everything in `CLAUDE.md` applies (tenancy, environments, DTO rule, problem+json, explicit listeners, secrets never logged/returned, `composer check` green before every commit, conventional commits, no work on `master`).
- Webhook events (exact names, spec §7.2): `document.validated`, `document.held`, `document.queued`, `document.submitted`, `document.valid`, `document.invalid`, `document.cancelled`, `document.rejected`, `document.consolidated`, `issuer.status_changed`, `certificate.expiring`, `certificate.expired` — plus `document.consolidation_failed` (added by this plan; spec amended in Task 7).
- Webhook signature: header `X-Einvoice-Signature` = `hash_hmac('sha256', $rawBody, $endpoint->secret)`; header `X-Einvoice-Event`; payload `{id, event, created_at, data}` where `data` is the existing `DocumentData`/`IssuerData` array. Endpoint secrets are `encrypted` casts, shown once at creation (`whsec_<40 random>`), never returned afterwards.
- Delivery retry curve `config('einvoice.webhooks.backoff_seconds')` = `[60, 300, 1800, 7200, 21600, 86400]` (≤ 24 h total per spec); statuses `pending → delivered | retrying → exhausted`; 2xx = delivered; anything else/timeout = retry; endpoint `enabled=false` is skipped at dispatch time.
- Consolidation (spec §5.6): daily job; previous month; issuers with `consolidation_enabled`; group `awaiting_consolidation` docs per issuer × currency (month is fixed by the run); parent invoice buyer = General Public (TIN `EI00000000010`), `consolidate=false`, one line per classification code (tax type `06`, description `Receipts <ref-first> to <ref-last> (<n> receipts)`), `source = {system: 'engine-consolidation', ref: 'cons-{issuerId}-{YYYY-MM}-{currency}'}`; children `awaiting_consolidation → consolidated` with `consolidated_into_id`; parent goes through the normal pipeline; parent `invalid` → children back `consolidated → awaiting_consolidation` (new transition) + webhook `document.consolidation_failed` per child parent.
- Status refresh: `valid` documents younger than `config('lhdn.status_refresh.max_age_days')` (7) whose `lhdn_refreshed_at` is null or older than `config('lhdn.status_refresh.interval_hours')` (6) get a `RefreshDocumentStatus` job via the sweep; `getDocument()` result maps `Rejected → rejected (buyer_rejected)`, `Cancelled → cancelled (cancelled_at_lhdn, only if isCancellable() else stays valid with a document_event note)` — ruling: LHDN-side cancellation outside our window still transitions to `cancelled` (LHDN is authoritative); relax `transition()`'s window check by passing a bypass flag? NO — instead `DocumentStateMachine::transition()` keeps its guard, and `RefreshDocumentStatus` uses a new `DocumentStateMachine::applyLhdnVerdict(Document, DocumentStatus, ?string reason)` that skips ONLY the cancellation-window guard (LHDN already did it) — same event/log path.
- Duplicate-submission recovery: when `SubmitDocuments` gets a rejection whose code is in `config('lhdn.duplicate_rejection_codes')` (default `['DUPLICATE_SUBMISSION']`) or whose message matches `/duplicat/i`, look up the most recent `submission_attempts` row (operation `submit`, this issuer) whose response `acceptedDocuments[].invoiceCodeNumber` equals this document's `lhdn_internal_id`; if found adopt `uuid` + that row's `submission_uid` → `queued → submitted` + poll; else `invalid` as today.
- PDF (spec §7.3): `GET /v1/documents/{id}/pdf` (`ability:read`) → `application/pdf`; available once `lhdn_uuid` and status ∈ {valid, cancelled, rejected} (cancelled/rejected watermarked); otherwise 409 `pdf_not_available`; rendered Blade → dompdf; QR (endroid, PNG data-URI) of the LHDN validation link `{portal}/{uuid}/share/{long_id}`; cached at `storage/app/documents/pdf/{tenant}/{id}.pdf` + `pdf_path` column; regenerated when the file is missing or `updated_at` is newer than the file.
- Certificate lifecycle (spec §7.4): daily command emits `certificate.expiring` (30/7/1 days before `cert_not_after`, once per threshold — dedupe via `issuer_secrets.expiry_notified_at_days` smallint nullable) and on expiry `certificate.expired` + `IssuerActivator::apply()` (→ suspended). New cert upload already re-activates and releases held docs (Plans 1–2).
- `submission_attempts` pruning: `einvoice:prune-attempts` deletes rows older than `config('lhdn.attempts_retention_days')` (default `2555` = ~7 years; env `LHDN_ATTEMPTS_RETENTION_DAYS`), chunked; scheduled daily.
- Every new tenant-scoped route gets sweep rows (tenant + environment axis where applicable). Webhook routes: `webhooks:manage`; pdf: `read`. Deterministic cursor order (`created_at desc, id desc`) on every list.
- Windows/Git Bash: focused runs `vendor/bin/pest <path>`; `composer check` before every commit; `failOnDeprecation` on; `LHDN_DRIVER=fake` in tests.

---

## File structure (created/modified across the tasks)

```
app/Enums/WebhookEvent.php  WebhookDeliveryStatus.php
app/Models/WebhookEndpoint.php  WebhookDelivery.php  (+ migrations, factories)
app/Data/Requests/Webhooks/{CreateWebhookEndpointData,UpdateWebhookEndpointData}.php
app/Data/Resources/{WebhookEndpointData,WebhookDeliveryData}.php
app/Webhooks/WebhookDispatcher.php        event+model → deliveries created + jobs dispatched
app/Webhooks/WebhookPayload.php           payload array builder
app/Jobs/DeliverWebhook.php               tenant-aware POST + retry curve
app/Listeners/{DispatchDocumentWebhooks,DispatchIssuerWebhooks}.php
app/Events/IssuerStatusChanged.php  CertificateExpiring.php  CertificateExpired.php
app/Http/Controllers/Api/V1/{WebhookEndpointController,WebhookDeliveryController}.php
app/Jobs/RefreshDocumentStatus.php        post-valid verdicts
app/Domain/Documents/DocumentStateMachine.php   (+ applyLhdnVerdict, consolidated→awaiting_consolidation)
app/Jobs/SubmitDocuments.php              (duplicate-rejection recovery)
app/Actions/Consolidation/ConsolidateIssuerMonth.php
app/Console/Commands/{ConsolidateDocuments,MonitorCertificates,PruneSubmissionAttempts}.php  LhdnDispatch.php (refresh sweep)
app/Pdf/DocumentPdfGenerator.php  resources/views/pdf/document.blade.php
app/Http/Controllers/Api/V1/DocumentPdfController.php
config/einvoice.php (webhooks), config/lhdn.php (status_refresh, duplicate codes, retention)
database/migrations/2026_08_22_0000{01..04}_*.php  routes/api.php  routes/console.php  AppServiceProvider
tests/Feature/Webhooks/*  tests/Feature/Lhdn/{StatusRefreshTest,DuplicateRecoveryTest}.php
tests/Feature/Consolidation/*  tests/Feature/Pdf/*  tests/Feature/Issuers/CertificateMonitorTest.php
```

Parallel waves for the executor: **{1 ∥ 2} → {3 ∥ 5} → {4 ∥ 6} → 7**.

---

### Task 1: Status refresh + LHDN-verdict transitions + duplicate-submission recovery

**Files:**
- Create: `app/Jobs/RefreshDocumentStatus.php`, `tests/Feature/Lhdn/StatusRefreshTest.php`, `tests/Feature/Lhdn/DuplicateRecoveryTest.php`, `database/migrations/2026_08_22_000001_add_lhdn_refreshed_at_to_documents_table.php`
- Modify: `app/Domain/Documents/DocumentStateMachine.php` (`applyLhdnVerdict`, `consolidated → awaiting_consolidation` map entry), `app/Models/Document.php` (`lhdn_refreshed_at` cast/docblock), `app/Jobs/SubmitDocuments.php` (duplicate recovery), `app/Jobs/PollSubmission.php` (share the verdict application via `applyLhdnVerdict` for Cancelled/Rejected), `app/Console/Commands/LhdnDispatch.php` (refresh sweep), `config/lhdn.php` (`status_refresh` + `duplicate_rejection_codes`), `.env.example`

**Interfaces:**
- Consumes: `LhdnClientFactory::for(Issuer)->getDocument(Issuer, string $uuid): DocumentDetails{uuid, status, longId, validationErrors}`; `FakeLhdnClient::markRejected/markCancelled/markInvalid`; `SubmissionAttempt` (`operation`, `submission_uid`, `response` array incl. `acceptedDocuments`); `TenantAwareJob` (+ `Queueable`, `tenantMiddleware()`), `DocumentStateMachine`, `HeldReason`, config.
- Produces:
  - `config('lhdn.status_refresh')` = `['max_age_days' => env LHDN_STATUS_REFRESH_MAX_AGE_DAYS (7), 'interval_hours' => env LHDN_STATUS_REFRESH_INTERVAL_HOURS (6)]`; `config('lhdn.duplicate_rejection_codes')` = `['DUPLICATE_SUBMISSION']`; `config('lhdn.attempts_retention_days')` = `2555` (used in Task 6).
  - `documents.lhdn_refreshed_at` nullable timestamp (+cast `datetime`).
  - `DocumentStateMachine::applyLhdnVerdict(Document $document, DocumentStatus $to, ?string $reason = null, array $meta = []): ?DocumentEvent` — like `transition()` but: allowed pairs only `valid→cancelled`, `valid→rejected`, `submitted→valid`, `submitted→invalid`; SKIPS the `isCancellable()` window guard (LHDN is authoritative); returns null (no-op) when `$document->status === $to`; still writes the event + dispatches `DocumentTransitioned`.
  - Map addition: `'consolidated' => ['awaiting_consolidation']` (used by Task 4).
  - `RefreshDocumentStatus(string $documentId)` tenant-aware job (`Queueable`, `tries 1`, `WithoutOverlapping("lhdn-refresh:{documentId}")->dontRelease()->expireAfter(120)`): loads the doc; skips unless status `valid` && `lhdn_uuid`; calls `getDocument`; maps LHDN status (case-insensitive): `rejected` → `applyLhdnVerdict(Rejected, 'buyer_rejected')`; `cancelled` → `applyLhdnVerdict(Cancelled, 'cancelled_at_lhdn')`; `valid`/anything else → no transition; always `forceFill(['lhdn_refreshed_at' => now()])->save()` on success; `LhdnException` → rethrow-free return (sweep retries later) EXCEPT Terminal 404 → also stamp `lhdn_refreshed_at` (document unknown to LHDN is a permanent answer for this cycle).
  - `LhdnDispatch` addition (after the poll block, same tenant/env loop): for `valid` docs with `lhdn_uuid` NOT NULL, `lhdn_status_at >= now()-max_age_days` and (`lhdn_refreshed_at` IS NULL OR `<= now()-interval_hours`) → `RefreshDocumentStatus::dispatch($doc->id)` (limit 50 per issuer per sweep, oldest `lhdn_refreshed_at` first).
  - `PollSubmission::apply()` post-valid branch now calls `applyLhdnVerdict` (drops its inline `isCancellable()` special case).
  - `SubmitDocuments` rejected branch: `if ($this->isDuplicateRejection($rejection))` → `recoverDuplicate($doc)`: query `SubmissionAttempt::where('issuer_id', $issuer->id)->where('operation', 'submit')->whereNotNull('submission_uid')->latest('created_at')->limit(20)->get()` and find the first whose `response['acceptedDocuments']` contains `invoiceCodeNumber === $doc->lhdn_internal_id`; on hit `forceFill(['lhdn_uuid' => uuid, 'lhdn_submission_uid' => attempt->submission_uid, 'last_submission_error' => null])` + `transition(Submitted, 'duplicate_recovered')` + dispatch `PollSubmission`; on miss → invalid as today.

- [ ] **Step 1: Write failing tests**

`tests/Feature/Lhdn/StatusRefreshTest.php`
```php
<?php

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Jobs\RefreshDocumentStatus;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
});

function validDoc(Issuer $issuer, array $attrs = []): Document
{
    return Document::factory()->for($issuer)->valid()->create($attrs);
}

it('applies buyer rejection and LHDN-side cancellation to valid documents', function () {
    $a = validDoc($this->issuer);
    $b = validDoc($this->issuer, ['lhdn_status_at' => now()->subHours(100)]); // outside our cancel window
    fakeLhdn()->getSubmission($this->issuer, 'noop'); // ensure fake exists
    // seed fake state: register docs then mark
    fakeLhdn()->markRejected($a->lhdn_uuid);
    fakeLhdn()->markCancelled($b->lhdn_uuid);
    // the fake's getDocument only knows uuids it created; seed via markInvalid-style registration:
    dispatch_sync(new RefreshDocumentStatus($a->id));
    dispatch_sync(new RefreshDocumentStatus($b->id));
    expect($a->refresh()->status)->toBe(DocumentStatus::Rejected)
        ->and($a->lhdn_refreshed_at)->not->toBeNull()
        ->and($a->events()->get()->last()->reason)->toBe('buyer_rejected')
        ->and($b->refresh()->status)->toBe(DocumentStatus::Cancelled); // window bypassed — LHDN is authoritative
});

it('stamps lhdn_refreshed_at without transitioning when LHDN still says valid, and skips non-valid docs', function () {
    $doc = validDoc($this->issuer);
    fakeLhdn()->registerDocument($doc->lhdn_uuid, 'Valid');
    dispatch_sync(new RefreshDocumentStatus($doc->id));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Valid)->and($doc->lhdn_refreshed_at)->not->toBeNull();

    $queued = Document::factory()->for($this->issuer)->queued()->create();
    dispatch_sync(new RefreshDocumentStatus($queued->id));
    expect($queued->refresh()->lhdn_refreshed_at)->toBeNull();
});

it('sweeps stale valid documents into refresh jobs', function () {
    Queue::fake([RefreshDocumentStatus::class]);
    $stale = validDoc($this->issuer, ['lhdn_refreshed_at' => now()->subHours(7)]);
    $fresh = validDoc($this->issuer, ['lhdn_refreshed_at' => now()->subHour()]);
    $old = validDoc($this->issuer, ['lhdn_status_at' => now()->subDays(10)]);
    app(TenantContext::class)->clear();
    Artisan::call('einvoice:lhdn-dispatch');
    Queue::assertPushed(RefreshDocumentStatus::class, fn ($j) => $j->documentId === $stale->id);
    Queue::assertNotPushed(RefreshDocumentStatus::class, fn ($j) => $j->documentId === $fresh->id);
    Queue::assertNotPushed(RefreshDocumentStatus::class, fn ($j) => $j->documentId === $old->id);
});

it('applyLhdnVerdict refuses pairs outside its whitelist and no-ops on same status', function () {
    $sm = app(DocumentStateMachine::class);
    $doc = validDoc($this->issuer);
    expect($sm->applyLhdnVerdict($doc, DocumentStatus::Valid))->toBeNull();
    expect(fn () => $sm->applyLhdnVerdict($doc, DocumentStatus::Queued))->toThrow(\App\Domain\Documents\InvalidTransition::class);
});
```
Note: the fake's document registry only contains uuids it issued. Add a scripting helper to `FakeLhdnClient`: `registerDocument(string $uuid, string $status, ?string $longId = null, array $errors = []): void` (inserts into the internal `$documents` map with `internalId ''`), and make `markRejected`/`markCancelled`/`markInvalid` auto-register unknown uuids (status first, defaults otherwise). Use it in these tests where noted.

`tests/Feature/Lhdn/DuplicateRecoveryTest.php`
```php
<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Jobs\PollSubmission;
use App\Jobs\SubmitDocuments;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
});

it('adopts the uuid from a prior submit attempt when LHDN rejects a duplicate codeNumber', function () {
    Queue::fake([PollSubmission::class]);
    $doc = Document::factory()->for($this->issuer)->queued()->create(['ubl_json' => '{"a":1}', 'lhdn_internal_id' => 'DOC-DUP-1']);
    SubmissionAttempt::factory()->for($this->issuer)->create([
        'operation' => 'submit', 'submission_uid' => 'SUB-LOST',
        'response' => ['submissionUid' => 'SUB-LOST', 'acceptedDocuments' => [['uuid' => 'UUID-LOST', 'invoiceCodeNumber' => 'DOC-DUP-1']]],
    ]);
    fakeLhdn()->rejectDocument('DOC-DUP-1', 'DUPLICATE_SUBMISSION', 'Duplicated submission');
    dispatch_sync(new SubmitDocuments($this->issuer->id));
    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Submitted)
        ->and($doc->lhdn_uuid)->toBe('UUID-LOST')
        ->and($doc->lhdn_submission_uid)->toBe('SUB-LOST')
        ->and($doc->events()->get()->last()->reason)->toBe('duplicate_recovered');
    Queue::assertPushed(PollSubmission::class, fn ($j) => $j->submissionUid === 'SUB-LOST');
});

it('falls back to invalid when no prior attempt carries the codeNumber', function () {
    $doc = Document::factory()->for($this->issuer)->queued()->create(['ubl_json' => '{"a":1}', 'lhdn_internal_id' => 'DOC-DUP-2']);
    fakeLhdn()->rejectDocument('DOC-DUP-2', 'DUPLICATE_SUBMISSION', 'Duplicated submission');
    dispatch_sync(new SubmitDocuments($this->issuer->id));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Invalid)->and($doc->lhdn_errors[0]['code'])->toBe('DUPLICATE_SUBMISSION');
});

it('does not treat ordinary rejections as duplicates', function () {
    $doc = Document::factory()->for($this->issuer)->queued()->create(['ubl_json' => '{"a":1}', 'lhdn_internal_id' => 'DOC-DUP-3']);
    fakeLhdn()->rejectDocument('DOC-DUP-3', 'CF321', 'Schema error');
    dispatch_sync(new SubmitDocuments($this->issuer->id));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Invalid)->and($doc->lhdn_errors[0]['code'])->toBe('CF321');
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/Lhdn/StatusRefreshTest.php tests/Feature/Lhdn/DuplicateRecoveryTest.php` → FAIL.

- [ ] **Step 3: Implement** — migration (nullable timestamp `lhdn_refreshed_at` after `lhdn_status_at` + index `['tenant_id','status','lhdn_refreshed_at']` named `documents_refresh_sweep_index`); `Document` cast/docblock; config keys + `.env.example`; `FakeLhdnClient::registerDocument()` + auto-register in `mark*`; `applyLhdnVerdict` in `DocumentStateMachine`:

```php
/** Allowed LHDN-authoritative verdicts (bypass the local cancellation window). */
private const LHDN_VERDICTS = [
    'valid' => ['cancelled', 'rejected'],
    'submitted' => ['valid', 'invalid'],
];

/** @param array<string, mixed> $meta */
public function applyLhdnVerdict(Document $document, DocumentStatus $to, ?string $reason = null, array $meta = []): ?DocumentEvent
{
    if ($document->status === $to) {
        return null;
    }
    if (! in_array($to->value, self::LHDN_VERDICTS[$document->status->value] ?? [], true)) {
        throw new InvalidTransition($document->status, $to);
    }

    return $this->performTransition($document, $to, $reason, $meta, null);
}
```
Refactor the body of `transition()` into a private `performTransition(Document, DocumentStatus, ?string, array, ?HeldReason): DocumentEvent` (the existing DB-transaction block) so both entrypoints share it; `transition()` keeps its map + window + held checks. Then `RefreshDocumentStatus` (mirror `PollSubmission`'s structure; use `applyLhdnVerdict`), the `LhdnDispatch` refresh block, `PollSubmission::apply()` post-valid branch → `applyLhdnVerdict`, and `SubmitDocuments::isDuplicateRejection()/recoverDuplicate()` per the Interfaces block.

- [ ] **Step 4: Run tests** — the two new files + `tests/Feature/Lhdn/SubmissionPipelineTest.php` + `tests/Unit/Lhdn` → pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(lhdn): post-valid status refresh, LHDN-verdict transitions, duplicate-submission recovery"
```

---

### Task 2: Webhook storage + CRUD API

**Files:**
- Create: `app/Enums/WebhookEvent.php`, `app/Enums/WebhookDeliveryStatus.php`, `database/migrations/2026_08_22_000002_create_webhook_endpoints_table.php`, `database/migrations/2026_08_22_000003_create_webhook_deliveries_table.php`, `app/Models/WebhookEndpoint.php`, `app/Models/WebhookDelivery.php`, `database/factories/WebhookEndpointFactory.php`, `database/factories/WebhookDeliveryFactory.php`, `app/Data/Requests/Webhooks/CreateWebhookEndpointData.php`, `app/Data/Requests/Webhooks/UpdateWebhookEndpointData.php`, `app/Data/Resources/WebhookEndpointData.php`, `app/Data/Resources/WebhookDeliveryData.php`, `app/Http/Controllers/Api/V1/WebhookEndpointController.php`, `tests/Feature/Webhooks/WebhookEndpointsTest.php`
- Modify: `routes/api.php`, `tests/Feature/TenantIsolationSweepTest.php`

**Interfaces:**
- Produces:
  - `enum WebhookEvent: string` — the 13 events from Global Constraints; `public static function values(): list<string>`.
  - `enum WebhookDeliveryStatus: string { Pending='pending'; Retrying='retrying'; Delivered='delivered'; Exhausted='exhausted' }`.
  - `webhook_endpoints`: ULID pk, `tenant_id` FK, `url` (500), `secret` text (encrypted cast, `$hidden`), `events` json, `enabled` bool default true, `environment` (16), `description` (255 nullable), timestamps; index `(tenant_id, environment, enabled)`.
  - `webhook_deliveries`: ULID pk, `tenant_id` FK, `webhook_endpoint_id` FK cascade, `event` (64), `payload` json, `status` (16) default pending, `attempt` unsignedSmallInteger default 0, `http_status` nullable, `response_snippet` (500 nullable), `error_message` (500 nullable), `delivered_at`/`next_retry_at` nullable timestamps, `created_at` (UPDATED_AT null on the model — no: keep both timestamps, deliveries are updated per attempt → use `timestamps()`); indexes `(tenant_id, webhook_endpoint_id, created_at)`, `(status, next_retry_at)`.
  - `WebhookEndpoint` (BelongsToTenant, HasUlids): `casts: secret encrypted, events array, enabled bool, environment Environment`; `static generate(...)`? No — controller creates with `secret = 'whsec_'.Str::random(40)`; method `listensTo(string $event): bool` (= enabled && in events); relation `deliveries()`.
  - `WebhookDelivery` (BelongsToTenant, HasUlids): casts `payload array`, `status WebhookDeliveryStatus`, timestamps; relation `endpoint()`.
  - DTOs: `CreateWebhookEndpointData(string $url /*url, max 500, https required unless host is localhost/127.0.0.1*/, array $events /*each in WebhookEvent::values(), min 1*/, ?string $description = null, bool $enabled = true)` with `rules()` (`'url' => ['required','string','max:500','url']` + closure rejecting non-https for non-local hosts → message 'Webhook URLs must use HTTPS.'; `'events' => ['required','array','min:1']`, `'events.*' => [Rule::in(WebhookEvent::values())]`); `UpdateWebhookEndpointData` all-Optional variants (same nested-safe style); `WebhookEndpointData(id, url, events, enabled, environment, description, created_at, updated_at, string|Optional $secret)` with `fromModel()` (+`withSecret()` once at creation, mirroring `ApiKeyData`); `WebhookDeliveryData(id, webhook_endpoint_id, event, status, attempt, http_status, response_snippet, error_message, delivered_at, next_retry_at, created_at)` `fromModel()`.
  - Routes (inside `tenant` group, `ability:webhooks:manage`): `GET/POST /webhooks`, `GET/PATCH/DELETE /webhooks/{webhookEndpoint}`, `GET /webhooks/{webhookEndpoint}/deliveries` (cursor list, deterministic order). The endpoint's environment is fixed at creation from `TenantContext::environment()`; lists/binding are environment-scoped exactly like `Issuer` (`scopeForCurrentEnvironment` + `resolveRouteBinding`). DELETE hard-deletes.
  - Audit: `webhook.created` (`{url, events}`), `webhook.updated` (diff via snapshot like `IssuerController`), `webhook.deleted`.
  - Tests: create (201, secret shown once starting `whsec_`, environment from context), list omits secret + only own env, update events/enabled, delete, validation (bad event name 422 pointer `/events/0`; http URL 422), cross-tenant/cross-env 404 (+ sweep rows for show/update/delete + env axis show), ability 403 (`read`-only key).

- [ ] **Step 1: Write failing tests `tests/Feature/Webhooks/WebhookEndpointsTest.php`** — cover the list above (follow `ApiKeysTest`/`IssuersTest` patterns; use `apiKeyHeaders($tenant, 'sandbox')` which includes `webhooks:manage`).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** per the Interfaces block (mirror `ApiKey` secret-once pattern and `Issuer` env-scoping; PHPStan docblocks).
- [ ] **Step 4: Run** — new file + sweep → pass; `composer check` green.
- [ ] **Step 5: Commit** — `feat(webhooks): tenant webhook endpoints CRUD with encrypted secrets and delivery log storage`

---
### Task 3: Webhook delivery — dispatcher, job, listeners, redeliver/test endpoints

**Files:**
- Create: `app/Webhooks/WebhookPayload.php`, `app/Webhooks/WebhookDispatcher.php`, `app/Jobs/DeliverWebhook.php`, `app/Events/IssuerStatusChanged.php`, `app/Listeners/DispatchDocumentWebhooks.php`, `app/Listeners/DispatchIssuerWebhooks.php`, `app/Http/Controllers/Api/V1/WebhookDeliveryController.php`, `tests/Feature/Webhooks/WebhookDeliveryTest.php`
- Modify: `config/einvoice.php` (`webhooks.backoff_seconds`, `webhooks.timeout`), `app/Providers/AppServiceProvider.php` (listeners), `app/Services/Issuers/IssuerActivator.php` + `app/Actions/Issuers/{VerifyIssuerTin,AuthorizeIssuer}.php` (dispatch `IssuerStatusChanged` on any status change), `routes/api.php`, `tests/Feature/TenantIsolationSweepTest.php`, `.env.example`

**Interfaces:**
- Consumes: Task 2 models/enums; `DocumentTransitioned(document, from, to, reason)` (after-commit); `DocumentData::fromModel()`, `IssuerData::fromModel()`; `TenantAwareJob` + `tenantMiddleware()`; `Http` client.
- Produces:
  - `config('einvoice.webhooks')` = `['backoff_seconds' => [60, 300, 1800, 7200, 21600, 86400], 'timeout' => (int) env('EINVOICE_WEBHOOK_TIMEOUT', 10)]`.
  - `WebhookPayload::document(string $event, Document $d): array` → `['id' => (string) Str::ulid(), 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => DocumentData::fromModel($d)->toArray()]`; `::issuer(string $event, Issuer $i, array $extra = []): array` (same shape; `data` = `IssuerData::fromModel($i)->toArray()` merged with `$extra`, e.g. `['expires_at' => …, 'days_left' => …]` for certificate events).
  - `WebhookDispatcher::dispatch(string $event, Environment $environment, array $payload): int` — finds `WebhookEndpoint` rows of the CURRENT tenant where `enabled`, `environment` matches, and `listensTo($event)`; for each creates a `WebhookDelivery` (`status pending, attempt 0, payload`) and `DeliverWebhook::dispatch($delivery->id)`; returns the number created. (Runs inside the tenant context of the caller — listeners run synchronously in-request or inside tenant-aware jobs, both bound.)
  - `DeliverWebhook(string $deliveryId)` tenant-aware job (`Queueable`, `tries 1`, no overlap middleware — one job per delivery row): loads delivery + endpoint (endpoint deleted/disabled → mark `exhausted`, `error_message 'endpoint_removed_or_disabled'`); POSTs `json` body = `json_encode($delivery->payload)` with headers `X-Einvoice-Event`, `X-Einvoice-Signature` (HMAC-SHA256 of the exact body string with the decrypted secret), `User-Agent: billplz-einvoice/1.0`, timeout from config; 2xx → `status delivered, delivered_at, http_status, attempt+1`; failure (non-2xx or `ConnectionException`) → `attempt+1`, `http_status`/`error_message` (500-char cap), `response_snippet` (500-char cap); if `attempt > count(backoff)` → `exhausted`; else `status retrying, next_retry_at = now + backoff[attempt-1]` and `self::dispatch($deliveryId)->delay(...)`. The secret is never logged or stored on the delivery row.
  - Listeners (explicit in `AppServiceProvider::boot()`): `DispatchDocumentWebhooks` on `DocumentTransitioned` — maps `to` status → event name (`validated, held, queued, submitted, valid, invalid, cancelled, rejected, consolidated` — `awaiting_consolidation` emits nothing) and `WebhookDispatcher::dispatch("document.{name}", $document->environment, WebhookPayload::document(...))`; `DispatchIssuerWebhooks` on `IssuerStatusChanged(Issuer $issuer, IssuerStatus $from, IssuerStatus $to)` → `issuer.status_changed` with `data.status_from/status_to` in `$extra`.
  - `IssuerStatusChanged` dispatched from every place the issuer status actually changes: `IssuerActivator::apply()` (after save, any change), `VerifyIssuerTin` (draft→tin_verified), `AuthorizeIssuer` (→authorized).
  - Endpoints (`ability:webhooks:manage`): `POST /webhooks/{webhookEndpoint}/test` → dispatches a synthetic `document.valid`-shaped payload `['id' => ulid, 'event' => 'webhook.test', 'created_at' => …, 'data' => ['message' => 'Test delivery from Billplz E-Invoice Engine']]` to just that endpoint (creates a delivery + job; 202 `{data: WebhookDeliveryData}`); `POST /webhook-deliveries/{webhookDelivery}/redeliver` → clones the delivery (`attempt 0, status pending`, same payload/event/endpoint) and dispatches; 202 with the NEW delivery. Both audit (`webhook.tested`, `webhook.redelivered`).
  - `WebhookDelivery::resolveRouteBinding` — tenant scope suffices (deliveries carry no environment; endpoint does); cross-tenant 404 via global scope + explicit binding (add `resolveRouteBinding` returning `static::query()->where(...)->first()` — the global scope already applies; default binding works, but add a sweep row to prove it).
- Tests (`Http::fake()`): delivered path asserts URL, headers (`X-Einvoice-Event`, signature = recomputed HMAC of the sent body), body shape, delivery row `delivered` with `http_status 200`; failure path (`Http::fake` 500 then `Queue::fake` for the re-dispatch) asserts `retrying`, `next_retry_at`, re-dispatch delayed; exhaustion (config backoff `[1]`, two failures) → `exhausted`; disabled endpoint → no delivery created; event filtering (endpoint listening only to `document.valid` gets nothing for `document.queued`); environment filtering (sandbox endpoint doesn't receive production doc events); end-to-end: creating a document through the API (sync queue) produces deliveries for `document.validated/queued/submitted/valid` in order; `issuer.status_changed` fires on authorize; test + redeliver endpoints (incl. 404 cross-tenant sweep rows + ability 403).

- [ ] **Step 1: Write failing tests `tests/Feature/Webhooks/WebhookDeliveryTest.php`** per the list above (use `Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)])`, `WebhookEndpoint::factory()` with known secret via `create(['secret' => 'whsec_known'])`, `fakeLhdn()` pipeline for the end-to-end case).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** per the Interfaces block. `DeliverWebhook` failure handling mirrors `SubmitDocuments`' style; signature computed over the exact `$body = (string) json_encode($payload)` that is sent via `Http::withBody($body, 'application/json')` (do NOT re-encode separately for the header). Listeners must be no-ops when the tenant has no endpoints (cheap `exists()` guard is unnecessary — the dispatcher query already returns empty).
- [ ] **Step 4: Run** — new tests + `tests/Feature/Lhdn/SubmissionPipelineTest.php` (pipeline now also dispatches webhooks — with no endpoints configured it must be a no-op and stay green) → pass; `composer check` green.
- [ ] **Step 5: Commit** — `feat(webhooks): signed delivery job with retry curve, document/issuer event fan-out, test and redeliver endpoints`

---

### Task 4: Consolidation

**Files:**
- Create: `app/Actions/Consolidation/ConsolidateIssuerMonth.php`, `app/Console/Commands/ConsolidateDocuments.php`, `app/Listeners/ReleaseChildrenOnConsolidationFailure.php`, `tests/Feature/Consolidation/ConsolidationTest.php`
- Modify: `routes/console.php` (daily schedule), `app/Providers/AppServiceProvider.php` (listener), `config/einvoice.php` (`consolidation.day_of_month_deadline` informational only — skip; no config needed), `tests/Feature/TenantIsolationSweepTest.php` (none — no new routes)

**Interfaces:**
- Consumes: `CreateDocument::handle(CreateDocumentData, ?string $groupId): DocumentCreated` (normal pipeline incl. queue/submit), `DocumentStateMachine::transition` (`awaiting_consolidation → consolidated`, `consolidated → awaiting_consolidation` from Task 1), `DocumentTransitioned`, `WebhookDispatcher` (Task 3), `TenantAwareJob` pattern via the command loop (the action runs inside the command's bound context; consolidation itself is synchronous per issuer×currency — document creation dispatches the async pipeline).
- Produces:
  - `ConsolidateIssuerMonth::handle(Issuer $issuer, CarbonImmutable $monthStart): list<Document>` — for each currency among the issuer's `awaiting_consolidation` docs with `issue_date` within `[$monthStart, $monthStart->endOfMonth()]`: build `CreateDocumentData` (type invoice, buyer `['general_public' => true]`, `consolidate => false`, `submit => true`, currency, `issue_date` = today KL, lines = one per `classification_code`: `quantity 1, unit_code 'C62', unit_price = <summed subtotal string>, tax_type '06', description "Receipts {minRef} to {maxRef} ({n} receipts)"` where min/max are the children's `source_ref` ordered, `metadata ['consolidation' => ['month' => 'YYYY-MM', 'children' => n]]`, source `{system: 'engine-consolidation', ref: "cons-{$issuer->id}-{YYYY-MM}-{$currency}"}`); create via `CreateDocument` (idempotent by natural key — re-running the same month is a replay, children already consolidated are excluded by status so no double-linking); then per child: `forceFill(['consolidated_into_id' => $parent->id])` + `transition(Consolidated, 'consolidated')`. Skips (returns []) when the issuer has no eligible docs. Note: discounts/taxes of children are already inside their `total_payable`; ruling — the consolidated line's `unit_price`/amount = SUM of child `total_payable` per classification code (LHDN consolidated invoices report gross receipts; tax type `06`).
  - `ConsolidateDocuments` command `einvoice:consolidate {--month=}` (default previous month, format `YYYY-MM`), scheduled `dailyAt('01:00')` KL — iterates tenants × environments with a system actor (mirror `LhdnDispatch` incl. context snapshot/restore), for each issuer with `consolidation_enabled && status active` calls the action; logs counts.
  - `ReleaseChildrenOnConsolidationFailure` on `DocumentTransitioned`: when `to === Invalid` and the document has children (`Document::where('consolidated_into_id', $document->id)` — tenant context is bound, event is after-commit), each child `transition(AwaitingConsolidation, 'consolidation_failed')` (from `consolidated`) and one `WebhookDispatcher::dispatch('document.consolidation_failed', env, WebhookPayload::document('document.consolidation_failed', $child))` per child; also clear the child's `consolidated_into_id`? Ruling: keep the link for audit; the next monthly run re-consolidates them into a new parent (their status is back to awaiting) and overwrites `consolidated_into_id`.
- Tests: two issuers, one with consolidation enabled; children in two currencies + one different classification code; run the command with `--month` → parent per currency created through the real pipeline (fake LHDN → parents end `valid`), children `consolidated` + linked, line grouping/description/sums asserted (`unit_price` = summed `total_payable` of that class code, parent `total_payable` = sum of all children in that currency); re-run → replay (no duplicate parents); parent invalid path: script `fakeLhdn()->rejectDocument(<parent internal id>, 'CF321', 'bad')` BEFORE running (parent internal id = its document id — unknown upfront; instead run with `Queue::fake([SubmitDocuments::class])` so the parent stays queued, then mark it invalid via the state machine? No — cleanest: create parent via command with Queue::fake to halt the pipeline, then `transition(parent, Invalid…)` is forbidden from queued? map has `queued → invalid` ✓ — use `app(DocumentStateMachine::class)->transition($parent, DocumentStatus::Invalid, 'rejected_at_submission')`) → children back to `awaiting_consolidation` + `document.consolidation_failed` deliveries created (webhook endpoint listening); disabled-issuer untouched; docs outside the month untouched.

- [ ] **Step 1: Write failing tests `tests/Feature/Consolidation/ConsolidationTest.php`** per above (create children via the API/action with `consolidate => true` on an active, consolidation-enabled issuer — they land in `awaiting_consolidation`; set their `issue_date` inside the target month).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** per the Interfaces block (money via `Money`/BigDecimal when summing).
- [ ] **Step 4: Run** — new tests + `SubmissionPipelineTest` + `StateMachineAdditionsTest` → pass; `composer check` green.
- [ ] **Step 5: Commit** — `feat(consolidation): monthly consolidated invoices per issuer/currency with failure release and webhooks`

---

### Task 5: PDF & QR

**Files:**
- Create: `app/Pdf/DocumentPdfGenerator.php`, `resources/views/pdf/document.blade.php`, `app/Http/Controllers/Api/V1/DocumentPdfController.php`, `tests/Feature/Pdf/DocumentPdfTest.php`
- Modify: `composer.json` (require `dompdf/dompdf` + `endroid/qr-code`), `routes/api.php`, `app/Listeners/` — none; invalidation is timestamp-based, `tests/Feature/TenantIsolationSweepTest.php`

**Interfaces:**
- Consumes: `Document` (+`lines`, `issuer`, `buyer_snapshot`, totals, `lhdn_uuid`, `lhdn_long_id`, `pdf_path`, `cancel_reason`), `config('lhdn.environments.{env}.portal_base')`, `Storage` (local disk).
- Produces:
  - Composer: `composer require dompdf/dompdf endroid/qr-code --no-interaction` (endroid v6 needs GD — present).
  - `DocumentPdfGenerator::generate(Document $document): string` — returns the storage path; renders `pdf/document.blade.php` (A4; issuer block, buyer block, document meta incl. type label + `lhdn_internal_id` + uuid + long id, line table with quantities/prices/discount/tax/total, totals box, QR image bottom-right, watermark text `CANCELLED`/`REJECTED` diagonally when status is cancelled/rejected + `cancel_reason`); QR: `Endroid\QrCode\Builder\Builder` PNG of the validation URL → `<img src="data:image/png;base64,...">`; dompdf `Options` `isRemoteEnabled false`; writes to `documents/pdf/{tenant_id}/{document_id}.pdf` on the `local` disk; `forceFill(['pdf_path' => $path])->save()`.
  - `DocumentPdfGenerator::stale(Document $document): bool` — no `pdf_path`, file missing, or `Storage::lastModified < $document->updated_at->getTimestamp()`.
  - `DocumentPdfController::show(Document $document)` (`GET /documents/{document}/pdf`, `ability:read`): status ∉ {valid, cancelled, rejected} OR `lhdn_uuid === null` → 409 `pdf_not_available` ("The PDF is available once LHDN validates the document."); else generate when `stale()`; `return response()->file(Storage::disk('local')->path($doc->pdf_path), ['Content-Type' => 'application/pdf'])`.
- Tests: valid doc (built through the fake pipeline) → 200, `Content-Type: application/pdf`, body starts `%PDF`, `pdf_path` set, file exists; second GET does not regenerate (assert `Storage::lastModified` unchanged — or spy: touch the file mtime and assert unchanged after second call); cancel the document (factory update `status cancelled, cancel_reason 'x'`, touch `updated_at`) → regenerated (mtime newer) — content-level watermark assertion is skipped (binary), assert regeneration only; queued doc → 409 `pdf_not_available`; cross-tenant/cross-env 404 sweep rows; `read` ability required (`documents:write`-only key → 403).

- [ ] **Step 1: Install deps** — `composer require dompdf/dompdf endroid/qr-code --no-interaction`; commit `composer.json`+lock separately if large: keep in the same task commit.
- [ ] **Step 2: Write failing tests `tests/Feature/Pdf/DocumentPdfTest.php`** (use `Storage::fake('local')`).
- [ ] **Step 3: Run to verify failure.**
- [ ] **Step 4: Implement** per the Interfaces block. Blade template: single file, inline CSS only (dompdf-safe: tables, no flexbox), no external assets. `Storage::fake` note: `response()->file` needs a real path — `Storage::disk('local')->path()` works with the fake (it maps to a temp dir).
- [ ] **Step 5: Run** — pass; `composer check` green (dompdf may emit deprecations under PHP 8.4 — if `failOnDeprecation` trips on vendor deprecations, add `<deprecation>` suppression? NO: instead set `error_reporting` for the render call? If dompdf triggers deprecations that fail tests, wrap the render in `@` is unacceptable — instead check `phpunit.xml`: `failOnDeprecation` counts only YOUR code's deprecations? It counts all. If vendor deprecations fire, add to `phpunit.xml` `<source ignoreSuppressionOfDeprecations="false"><deflect>`… the pragmatic escape: `withoutDeprecationHandling`? — if this happens, report DONE_WITH_CONCERNS and propose the `baselineFile` for deprecations; do NOT silently weaken the flag.)
- [ ] **Step 6: Commit** — `feat(pdf): invoice PDF with LHDN QR validation link, lazy cache and status watermark`

---
### Task 6: Certificate expiry monitor + attempts pruning

**Files:**
- Create: `app/Events/CertificateExpiring.php`, `app/Events/CertificateExpired.php`, `app/Listeners/DispatchCertificateWebhooks.php`, `app/Console/Commands/MonitorCertificates.php`, `app/Console/Commands/PruneSubmissionAttempts.php`, `database/migrations/2026_08_22_000004_add_expiry_notified_at_days_to_issuer_secrets_table.php`, `tests/Feature/Issuers/CertificateMonitorTest.php`
- Modify: `routes/console.php` (both schedules), `app/Providers/AppServiceProvider.php` (listener), `config/lhdn.php` (`attempts_retention_days` if not added in Task 1 — it was; verify), `.env.example`

**Interfaces:**
- Consumes: `Issuer` (`certificate_valid_until`, `status`, `secret` → `cert_not_after`), `IssuerActivator::apply()` (active+expired → suspended; dispatches `IssuerActivated` only on activation — status change event from Task 3 `IssuerStatusChanged` fires for suspension too), `WebhookDispatcher`/`WebhookPayload::issuer` (Task 3), `AuditLogger`, `SubmissionAttempt`.
- Produces:
  - `issuer_secrets.expiry_notified_at_days` unsignedSmallInteger nullable (last threshold notified: 30, 7, or 1; reset to null when a new certificate is uploaded — add that reset in `IssuerCertificateController`'s transaction).
  - `CertificateExpiring(Issuer $issuer, int $daysLeft)` / `CertificateExpired(Issuer $issuer)` plain events; `DispatchCertificateWebhooks` listener (explicit) → `certificate.expiring` (`extra: {expires_at, days_left}`) / `certificate.expired` (`extra: {expired_at}`) via `WebhookDispatcher` + audit `certificate.expiring`/`certificate.expired`.
  - `MonitorCertificates` command `einvoice:monitor-certificates`, scheduled `dailyAt('02:00')` KL — iterates tenants × environments (system actor, context snapshot/restore like `LhdnDispatch`): for issuers with a certificate (`certificate_valid_until` not null): expired (`< now`) && status `active` → `IssuerActivator::apply()` (→ suspended; `IssuerStatusChanged` fires from Task 3 wiring) + `CertificateExpired::dispatch`; not expired → thresholds `[30, 7, 1]`: if `daysLeft <= t` and (`expiry_notified_at_days` is null or `> t`... careful: notified value 30 means "30-day notice sent"; send when `daysLeft <= t && (notified === null || notified > t)`) → `CertificateExpiring::dispatch($issuer, $daysLeft)` + `secret->forceFill(['expiry_notified_at_days' => t])->save()`; only ONE notice per run (the smallest matching t).
  - `PruneSubmissionAttempts` command `einvoice:prune-attempts {--days=}` (default `config('lhdn.attempts_retention_days')`), scheduled `dailyAt('03:30')`: `SubmissionAttempt::withoutGlobalScopes()->where('created_at', '<', $cutoff)` deleted in chunks of 1000 (system maintenance job — the `withoutGlobalScopes` use is the sanctioned system-job exception; add a comment citing CLAUDE.md); prints the count.
- Tests: issuer with cert expiring in 29 days → first run emits `certificate.expiring` webhook delivery (endpoint listening) + audit + `expiry_notified_at_days = 30`; second run same day → no duplicate; travel to 6 days → `expiring` again with `notified = 7`; travel past expiry → `certificate.expired`, issuer `suspended`, `issuer.status_changed` delivery; upload a new cert (reuse Plan 1 fixtures via the API) → issuer active again, `expiry_notified_at_days` reset null; pruning: two attempts (old + recent), `--days=30` → old gone, recent kept, count printed; scheduled entries asserted via `Schedule` inspection? (skip — command tests suffice).

- [ ] **Step 1: Write failing tests `tests/Feature/Issuers/CertificateMonitorTest.php`** per above (`$this->travel()->days()`, webhook endpoint via `WebhookEndpoint::factory()` listening to the certificate events + `Http::fake`).
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** per the Interfaces block.
- [ ] **Step 4: Run** — new tests + `IssuerSecretsTest` + `WebhookDeliveryTest` → pass; `composer check` green.
- [ ] **Step 5: Commit** — `feat(issuers): certificate expiry monitor with webhooks and suspension; submission attempts pruning`

---

### Task 7: Docs, spec amendments, sweep completeness, roadmap outcome placeholder

**Files:**
- Modify: `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` (§7.2: custom tenant-aware delivery job instead of spatie/laravel-webhook-server + the `document.consolidation_failed` event + decisions-log rows; §5.6 note `consolidated → awaiting_consolidation` on parent failure; §6.5 note buyer rejection now detected via the status-refresh job; §7.3 lazy PDF note; §7.4 "as built" note), `docs/lhdn-gateway.md` (status refresh + duplicate recovery sections; remove the "not yet built (Plan 4)" items now built), `README.md` (webhooks section: signature verification example; PDF endpoint; new commands/schedules), `docs/superpowers/plans/2026-08-19-einvoice-engine-roadmap.md` (row 4 filename), `tests/Feature/TenantIsolationSweepTest.php` (verify every Plan 4 route has rows — webhooks CRUD/test/redeliver/deliveries list, pdf; add any missing), `CLAUDE.md` (no change expected — verify)

**Interfaces:**
- Produces: accurate docs; a `## Plan 4 outcome` roadmap section is written by the CONTROLLER after the final review (do not write it here); every doc claim must be true of the code (the Plan 3 final review flagged aspirational docs — do not repeat that).
- Also: run a final self-check `grep -rn "spatie/laravel-webhook-server" docs/ composer.json` → only historical mentions in outcome sections remain (roadmap history is fine); `php artisan route:list` sanity vs README.

- [ ] **Step 1: Make the edits** (read each target section first; keep amendments dated like the Plan 3 ones).
- [ ] **Step 2: Sweep audit** — enumerate the routes added in Plan 4 vs the sweep datasets; add missing rows (tests must pass).
- [ ] **Step 3: Run** — `composer check` green.
- [ ] **Step 4: Commit** — `docs: Plan 4 spec amendments (webhooks delivery, consolidation failure, status refresh), guides and sweep audit`

---

## Plan self-review (done at authoring time)

- **Spec coverage:** §5.6 consolidation (grouping, general-public parent, one line per classification code with receipt range, pipeline submission, child linking, failure release + alert webhook) → Task 4; §7.2 webhooks (per-tenant endpoints, HMAC, retries ≤24h, deliveries log, redeliver, full event list) → Tasks 2–3 (delivery mechanism amended in Task 7); §7.3 PDF+QR (valid-only, cached, regenerated on cancel — implemented as lazy regeneration; recorded ruling) → Task 5; §7.4 cert lifecycle (30/7/1 notices, expiry → suspended + held docs (already held by PrepareDocument), re-activation on upload (existing)) → Task 6; §8 rows `GET /documents/{id}/pdf`, `POST/GET/PATCH/DELETE /webhooks…`, `POST /webhooks/{id}/test`, `/webhook-deliveries/{id}/redeliver` → Tasks 2, 3, 5; §6.5 completion (buyer rejection via refresh) + Plan 3 backlog items (status refresh, duplicate codeNumber, attempts retention) → Tasks 1, 6.
- **Placeholders:** none — Tasks 2–6 specify exact interfaces/columns/rules in their Interfaces blocks with test lists; full code is given where behaviour is novel (Task 1) and the rest mirrors named existing patterns file-by-file. The dompdf-deprecation contingency in Task 5 names the exact escalation (DONE_WITH_CONCERNS + proposal), not a silent workaround.
- **Type consistency:** `WebhookDispatcher::dispatch(string, Environment, array): int` used by Tasks 3, 4, 6; `WebhookPayload::document/issuer` shapes shared; `applyLhdnVerdict` (Task 1) used by Tasks 1 and 4 tests; `DeliverWebhook(string $deliveryId)`; `ConsolidateIssuerMonth::handle(Issuer, CarbonImmutable): list<Document>`; `DocumentPdfGenerator::generate/stale`; enums `WebhookEvent::values()`, `WebhookDeliveryStatus`; `expiry_notified_at_days` reset added to `IssuerCertificateController` (Task 6 modifies it).
