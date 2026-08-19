# E-Invoice Engine — Plan 3: LHDN Gateway Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Connect the engine to LHDN MyInvois: `LhdnClient` implementations (intermediary / own-credentials / fake), per-issuer token cache, UBL 2.1 JSON builder, XAdES-style JSON signer, the submission pipeline (prepare → batch submit → poll), rate limiting + circuit breaker, issuer TIN verification + authorisation, TIN validation, cancellation/rejection, and opt-in sandbox tests — so `queued` documents become `valid`/`invalid` at LHDN.

**Architecture:** All MyInvois calls go through `App\Lhdn\LhdnClient` (never from controllers); `LhdnClientFactory` picks the implementation per issuer (`lhdn_mode`) and environment, or `FakeLhdnClient` when `LHDN_DRIVER=fake` (tests). Every request/response is stored in `submission_attempts`. Domain work (`UblDocumentBuilder`, `DocumentSigner`) is pure PHP. Pipeline jobs are tenant-aware (`TenantAwareJob` + `Queueable`), idempotent, rate-limited per issuer, and move documents only through `DocumentStateMachine`. A scheduler command sweeps for stragglers.

**Tech Stack:** Laravel 12 (Http client, Cache locks, RateLimiter, queues, scheduler), PHP OpenSSL (RSA-SHA256 signing, X.509 parsing), brick/math, spatie/laravel-data, Pest, Larastan 8.

**Spec:** `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` §4.4, §6.1–6.5, §8 (verify-tin, authorize, tin/validate, submit, cancel), §9, §10. Roadmap row 3 + "Plan 2 outcome" backlog.

## Global Constraints

- Everything in `CLAUDE.md` applies (tenancy, environments, DTO rule, problem+json, secrets never logged/returned, `composer check` green before every commit, explicit listener registration, no work on `master`).
- **LHDN gateway rule:** all MyInvois calls go through `App\Lhdn\LhdnClient` implementations; never call LHDN from controllers; respect the per-issuer token cache and per-issuer rate budgets; every request/response is stored in `submission_attempts` (secrets and bearer tokens redacted).
- State changes go through `DocumentStateMachine` only (transition map extended in Task 1: `queued → invalid`, `held → held`; `transition()` takes a typed `?HeldReason`).
- Environments: LHDN base URLs per environment from `config/lhdn.php`; an issuer's client always uses the issuer's environment; `ek_test_` credentials never reach production URLs.
- LHDN payload limits (spec §6.3): ≤ 100 documents and ≤ 5 MB per submission, ≤ 300 KB per document; `documentHash` = SHA-256 hex of the exact JSON string submitted; `document` = base64 of that string; `format` = `JSON`; `codeNumber` = `documents.lhdn_internal_id` (= the document ULID — recorded decision; amend spec §5.2).
- UBL JSON follows the LHDN MyInvois SDK v1.1 JSON shape (`_D`/`_A`/`_B` namespaces, arrays of objects with `_` values, `InvoiceTypeCode.listVersionID = "1.1"` when signed). The signature follows the SDK's JSON signing procedure (digests: document minus `UBLExtensions`/`Signature`, SHA-256; cert digest; signed-properties digest; RSA-SHA256 signature value; `UBLExtensions → UBLDocumentSignatures → SignatureInformation → Signature`).
- Error classification: `LhdnErrorKind::Transient` (429, 5xx, network) → retry with backoff, max `config('lhdn.submission.max_attempts')` (8) then `held: lhdn_unavailable`; `Auth` (401/403) → `held: lhdn_credentials_invalid` + token cache forget; `Terminal` (other 4xx) → `invalid` with mapped errors; `Breaker` (circuit open) → release/back-off. Problem mapping: Transient/Breaker → 503 `lhdn_unavailable`; Auth → 409 `lhdn_credentials_invalid`; Terminal → 422 `lhdn_rejected`.
- Money/UBL amounts are decimal strings; UBL numeric fields are emitted as JSON numbers with 2 dp (LHDN accepts numbers); quantities up to 4 dp.
- Every new tenant-scoped route gets sweep rows (tenant + environment axis). Abilities: issuer endpoints `issuers:manage`; `tin/validate` `read`; cancel `documents:write`.
- Tests never touch the network: `phpunit.xml` sets `LHDN_DRIVER=fake`; `Http::fake()` for `HttpLhdnClient` unit tests; opt-in `LHDN_SANDBOX_TESTS=1` integration tests only.
- Windows/Git Bash: `vendor/bin/pest <path>` for focused runs; `composer check` before every commit.

---

## File structure (created/modified across the tasks)

```
config/lhdn.php                                     driver, env base URLs, intermediary creds, limits, breaker, backoff
app/Lhdn/
  LhdnClient.php (interface)  LhdnClientFactory.php  LhdnCredentials.php  CredentialsResolver.php
  LhdnException.php  LhdnErrorKind.php (enum)
  Data/AccessToken.php  SubmissionDocument.php  SubmissionBatch.php  SubmissionResult.php
       SubmissionStatus.php  DocumentSummary.php  DocumentDetails.php
  Http/HttpLhdnClient.php  TokenProvider.php  AttemptRecorder.php
  Fake/FakeLhdnClient.php
  CircuitBreaker.php  LhdnRateLimiter.php
  Ubl/UblDocumentBuilder.php  Ubl/UblParty.php (helper) 
  Signing/DocumentSigner.php  Signing/SignedDocument.php  Signing/CertificateInfoExtractor.php
app/Domain/Documents/DocumentStateMachine.php       (modified: typed HeldReason, new transitions)
app/Models/SubmissionAttempt.php  app/Models/Document.php (new columns)
database/migrations/2026_08_21_000001_add_lhdn_columns_to_documents_table.php
database/migrations/2026_08_21_000002_create_submission_attempts_table.php
app/Actions/Issuers/VerifyIssuerTin.php  AuthorizeIssuer.php   app/Actions/Tin/ValidateTin.php
app/Actions/Documents/CancelDocument.php  (SubmitDocument.php modified: allow invalid → queued)
app/Jobs/PrepareDocument.php  SubmitDocuments.php  PollSubmission.php
app/Listeners/PrepareDocumentOnQueued.php
app/Console/Commands/LhdnDispatch.php               scheduler sweep (every minute)
app/Data/Requests/Tin/ValidateTinData.php  app/Data/Requests/Documents/CancelDocumentData.php
app/Data/Resources/TinValidationData.php  (DocumentData: + lhdn.validation_url)
app/Http/Controllers/Api/V1/IssuerOnboardingController.php  TinController.php  (DocumentController: cancel)
app/Http/Problem/ProblemResponse.php                (LhdnException mapping)
routes/api.php  routes/console.php  bootstrap/app.php (nothing) AppServiceProvider (bindings + listener)
tests/Unit/Lhdn/{StateMachineAdditionsTest,FakeLhdnClientTest,HttpLhdnClientTest,TokenProviderTest,CircuitBreakerTest,UblDocumentBuilderTest,DocumentSignerTest}.php
tests/Feature/Lhdn/{IssuerOnboardingTest,TinValidateTest,SubmissionPipelineTest,CancelDocumentTest}.php
tests/Integration/LhdnSandboxTest.php  tests/Fixtures/ubl/*.json (golden)  tests/Pest.php (fakeLhdn() helper)
```

Parallel waves for the executor: **{1 ∥ 2} → {3 ∥ 4 ∥ 5} → {6 ∥ 7} → 8 → 9**.

---

### Task 1: State machine additions + LHDN columns + `submission_attempts`

**Files:**
- Modify: `app/Domain/Documents/DocumentStateMachine.php`, `app/Models/Document.php`, `app/Actions/Documents/CreateDocument.php` (pass enum), `app/Jobs/ReleaseHeldDocuments.php` (unchanged call ok), `app/Actions/Documents/SubmitDocument.php`, `tests/Unit/Documents/DocumentStateMachineTest.php`, `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` (§5.2 `lhdn_internal_id` note; §5.3 new transitions)
- Create: `database/migrations/2026_08_21_000001_add_lhdn_columns_to_documents_table.php`, `database/migrations/2026_08_21_000002_create_submission_attempts_table.php`, `app/Models/SubmissionAttempt.php`, `database/factories/SubmissionAttemptFactory.php`, `tests/Unit/Lhdn/StateMachineAdditionsTest.php`

**Interfaces:**
- Consumes: Plan 2 `DocumentStateMachine`, `Document`, `HeldReason`, `DocumentStatus`.
- Produces:
  - `DocumentStateMachine::transition(Document $document, DocumentStatus $to, ?string $reason = null, array $meta = [], ?HeldReason $heldReason = null): DocumentEvent` — when `$to === Held`, `$heldReason` is REQUIRED (throws `InvalidArgumentException` if null); `held_reason` is set from it; `$reason` defaults to `$heldReason->value` for the event log. Existing callers that passed the enum value as `$reason` must be updated to pass the enum.
  - Transition map additions: `queued → invalid` (rejected at submission), `held → held` (re-hold with a new reason), `submitted → held` (auth failure discovered while polling is NOT needed — keep map minimal: add only the first two).
  - `documents` new columns: `lhdn_internal_id string(50) nullable unique per tenant` (set to the document id at creation — `CreateDocument` sets `'lhdn_internal_id' => $document->id` right after create), `ubl_json longText nullable`, `signed_payload_hash char(64) nullable`, `pdf_path string nullable`, `submission_attempts_count unsignedSmallInteger default 0`, `last_submission_error json nullable`, `next_submission_at timestamp nullable`.
  - `SubmissionAttempt` model (BelongsToTenant, HasUlids, `UPDATED_AT = null`): `issuer_id`, `document_id?`, `submission_uid?`, `operation` (token|submit|get_submission|get_document|cancel|validate_tin), `environment`, `http_status?`, `request json?` (redacted), `response json?`, `error_kind?`, `error_message?`, `duration_ms`, `created_at`. Indexes `(tenant_id, issuer_id, created_at)`, `(document_id)`, `(submission_uid)`.
  - `SubmitDocument::handle()` now also accepts `invalid` (→ queued, reason `resubmit`).

- [ ] **Step 1: Write failing tests `tests/Unit/Lhdn/StateMachineAdditionsTest.php`**

```php
<?php

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->sm = new DocumentStateMachine;
});

it('requires a typed HeldReason when holding and records it', function () {
    $doc = Document::factory()->for($this->issuer)->create(['status' => 'validated']);
    $this->sm->transition($doc, DocumentStatus::Held, heldReason: HeldReason::LhdnUnavailable);
    expect($doc->refresh()->held_reason)->toBe(HeldReason::LhdnUnavailable)
        ->and($doc->events()->get()->last()->reason)->toBe('lhdn_unavailable');
    expect(fn () => $this->sm->transition(Document::factory()->for($this->issuer)->create(['status' => 'validated']), DocumentStatus::Held))
        ->toThrow(InvalidArgumentException::class);
});

it('allows held → held re-hold and queued → invalid', function () {
    $doc = Document::factory()->for($this->issuer)->held(HeldReason::IssuerNotActive)->create();
    $this->sm->transition($doc, DocumentStatus::Held, heldReason: HeldReason::LhdnUnavailable);
    expect($doc->refresh()->held_reason)->toBe(HeldReason::LhdnUnavailable);
    $q = Document::factory()->for($this->issuer)->queued()->create();
    $this->sm->transition($q, DocumentStatus::Invalid, 'rejected_at_submission', ['errors' => [['code' => 'X', 'message' => 'bad']]]);
    expect($q->refresh()->status)->toBe(DocumentStatus::Invalid)->and($q->lhdn_status_at)->not->toBeNull();
});

it('stores submission attempts tenant-scoped with redacted payloads', function () {
    $a = SubmissionAttempt::factory()->for($this->issuer)->create(['operation' => 'token', 'request' => ['client_id' => 'abc***'], 'http_status' => 200]);
    expect(SubmissionAttempt::count())->toBe(1)->and($a->tenant_id)->toBe($this->tenant->id);
    app(TenantContext::class)->bind(Tenant::factory()->create(), null, Environment::Sandbox);
    expect(SubmissionAttempt::count())->toBe(0);
});

it('has the new document columns', function () {
    $doc = Document::factory()->for($this->issuer)->queued()->create(['lhdn_internal_id' => 'X1', 'ubl_json' => '{"a":1}', 'signed_payload_hash' => str_repeat('a', 64), 'submission_attempts_count' => 2]);
    expect($doc->refresh()->ubl_json)->toBe('{"a":1}')->and($doc->submission_attempts_count)->toBe(2)->and($doc->lhdn_internal_id)->toBe('X1');
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Lhdn/StateMachineAdditionsTest.php` → FAIL.

- [ ] **Step 3: Migrations**

`database/migrations/2026_08_21_000001_add_lhdn_columns_to_documents_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('lhdn_internal_id', 50)->nullable()->after('payload_hash');
            $table->longText('ubl_json')->nullable()->after('lhdn_internal_id');
            $table->char('signed_payload_hash', 64)->nullable()->after('ubl_json');
            $table->string('pdf_path')->nullable()->after('signed_payload_hash');
            $table->unsignedSmallInteger('submission_attempts_count')->default(0)->after('pdf_path');
            $table->json('last_submission_error')->nullable()->after('submission_attempts_count');
            $table->timestamp('next_submission_at')->nullable()->after('last_submission_error');
            $table->unique(['tenant_id', 'lhdn_internal_id'], 'documents_lhdn_internal_id_unique');
            $table->index(['tenant_id', 'lhdn_submission_uid'], 'documents_submission_uid_index');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_lhdn_internal_id_unique');
            $table->dropIndex('documents_submission_uid_index');
            $table->dropColumn(['lhdn_internal_id', 'ubl_json', 'signed_payload_hash', 'pdf_path', 'submission_attempts_count', 'last_submission_error', 'next_submission_at']);
        });
    }
};
```

`database/migrations/2026_08_21_000002_create_submission_attempts_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('submission_uid', 64)->nullable();
            $table->string('operation', 20);
            $table->string('environment', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error_kind', 16)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'issuer_id', 'created_at']);
            $table->index('document_id');
            $table->index('submission_uid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_attempts');
    }
};
```

- [ ] **Step 4: Model + factory**

`app/Models/SubmissionAttempt.php`
```php
<?php

namespace App\Models;

use App\Enums\Environment;
use App\Tenancy\BelongsToTenant;
use Database\Factories\SubmissionAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $issuer_id
 * @property string|null $document_id
 * @property string|null $submission_uid
 * @property string $operation
 * @property Environment $environment
 * @property int|null $http_status
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error_kind
 * @property string|null $error_message
 * @property int $duration_ms
 * @property Carbon $created_at
 */
class SubmissionAttempt extends Model
{
    /** @use HasFactory<SubmissionAttemptFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'request' => 'array',
            'response' => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Issuer, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
```

`database/factories/SubmissionAttemptFactory.php`
```php
<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionAttempt> */
class SubmissionAttemptFactory extends Factory
{
    protected $model = SubmissionAttempt::class;

    public function definition(): array
    {
        return [
            'issuer_id' => Issuer::factory(),
            'operation' => 'submit',
            'environment' => Environment::Sandbox,
            'http_status' => 200,
            'request' => ['documents' => 1],
            'response' => ['ok' => true],
            'duration_ms' => 120,
            'created_at' => now(),
        ];
    }
}
```

`Document` model: add `@property` docblocks for the seven new columns and casts `'last_submission_error' => 'array', 'next_submission_at' => 'datetime', 'submission_attempts_count' => 'integer'`.

- [ ] **Step 5: State machine changes** — in `DocumentStateMachine`:
  - `TRANSITIONS`: `'held' => ['queued', 'held']`, `'queued' => ['submitted', 'held', 'invalid']` (rest unchanged).
  - Signature: `public function transition(Document $document, DocumentStatus $to, ?string $reason = null, array $meta = [], ?HeldReason $heldReason = null): DocumentEvent`. At the top: `if ($to === DocumentStatus::Held && $heldReason === null) { throw new \InvalidArgumentException('A HeldReason is required when transitioning to held.'); }` and `$reason ??= $heldReason?->value;`. Replace `HeldReason::from($reason)` with `$to === DocumentStatus::Held ? $heldReason : null`.
  - Update callers: `CreateDocument::route()` → `transition($document, Held, heldReason: HeldReason::EinvoiceNotRequired)` etc.; `ReleaseHeldDocuments` unchanged (queued); `DocumentStateMachineTest` "records an event…" test: `transition($doc, Held, heldReason: HeldReason::IssuerNotActive)`; Plan 2 test using `'issuer_not_active'` string → enum.
  - `CreateDocument::handle()`: after `Document::create([...])` add `$document->forceFill(['lhdn_internal_id' => $document->id])->save();` (or include in create by pre-generating the ULID: `$id = (string) Str::ulid(); Document::create(['id' => $id, 'lhdn_internal_id' => $id, …])` — `$guarded` blocks `id`; use the forceFill approach).
  - `SubmitDocument::handle()`: allowed statuses `Validated, Held, Invalid`; reason `'resubmit'` when coming from invalid else `'manual_submit'`.
  - Spec: §5.2 add `lhdn_internal_id` ("= document id; used as LHDN `codeNumber`/`Invoice.ID`"), `ubl_json`, `signed_payload_hash`, `pdf_path`, `submission_attempts_count`, `last_submission_error`, `next_submission_at`; §5.3 diagram add `queued → invalid` and `held → held`; §5.3 text: "`transition()` takes a typed `HeldReason`".

- [ ] **Step 6: Run tests** — `vendor/bin/pest tests/Unit/Lhdn tests/Unit/Documents tests/Feature/Documents` → pass; `composer check` green.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(documents): typed HeldReason, queued→invalid/held→held, LHDN columns, submission_attempts"
```

---

### Task 2: LHDN client contract, DTOs, exceptions, config, factory, `FakeLhdnClient`

**Files:**
- Create: `config/lhdn.php`, `app/Lhdn/LhdnClient.php`, `app/Lhdn/LhdnErrorKind.php`, `app/Lhdn/LhdnException.php`, `app/Lhdn/LhdnCredentials.php`, `app/Lhdn/CredentialsResolver.php`, `app/Lhdn/LhdnClientFactory.php`, `app/Lhdn/Data/{AccessToken,SubmissionDocument,SubmissionBatch,SubmissionResult,SubmissionStatus,DocumentSummary,DocumentDetails}.php`, `app/Lhdn/Fake/FakeLhdnClient.php`, `tests/Unit/Lhdn/FakeLhdnClientTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (singleton `FakeLhdnClient`), `phpunit.xml` (`<env name="LHDN_DRIVER" value="fake"/>`), `.env.example`, `tests/Pest.php` (`fakeLhdn()` helper)

**Interfaces:**
- Produces:
  - `config('lhdn')`: `driver` (`http`|`fake`, env `LHDN_DRIVER`, default `http`), `environments.{sandbox|production}.{api_base,identity_base,portal_base}`, `intermediary.{sandbox|production}.{client_id,client_secret}` (env `LHDN_SANDBOX_CLIENT_ID`… `LHDN_PRODUCTION_CLIENT_SECRET`), `timeout` (30), `token_ttl_margin_seconds` (60), `rate_limits` per minute per issuer (`token 12, submit 100, get_submission 300, get_document 60, cancel 12, validate_tin 60`), `circuit_breaker.{failure_threshold:5, cooldown_seconds:60}`, `submission.{max_documents:100, max_bytes:5242880, max_document_bytes:307200, max_attempts:8, retry_backoff_seconds:[30,60,120,300,600,1200,1800,3600]}`, `poll.backoff_seconds` (`[5,15,30,60,120,300,600,1800,3600]`), `tin_cache_hours` (24).
  - `enum LhdnErrorKind: string { Transient='transient'; Auth='auth'; Terminal='terminal'; Breaker='breaker' }`
  - `LhdnException(string $message, LhdnErrorKind $kind, ?int $httpStatus = null, array $payload = [])` with readonly props + `static transient/auth/terminal/breaker(...)` helpers.
  - `LhdnCredentials(string $clientId, string $clientSecret, ?string $onBehalfOf, string $mode /*intermediary|own*/)`; `cacheKeyPart(): string` = `"{mode}:" . sha1($clientId.'|'.($onBehalfOf ?? ''))`.
  - `CredentialsResolver::for(Issuer $issuer): LhdnCredentials` — `intermediary` → config creds for the issuer's environment + `onBehalfOf = issuer->tin` (throws `LhdnException::auth('Intermediary credentials are not configured for {env}')` when blank); `own_credentials` → `IssuerSecret` `lhdn_client_id/secret` (throws `LhdnException::auth('Issuer credentials missing')` when absent). `forIntermediary(Environment): LhdnCredentials` (no onBehalfOf).
  - `interface LhdnClient { token(Issuer): AccessToken; submitDocuments(Issuer, SubmissionBatch): SubmissionResult; getSubmission(Issuer, string $submissionUid): SubmissionStatus; getDocument(Issuer, string $uuid): DocumentDetails; cancelDocument(Issuer, string $uuid, string $reason): void; validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool; }`
  - DTOs (final readonly classes): `AccessToken(string $token, CarbonImmutable $expiresAt)` + `isExpired(int $marginSeconds = 0)`; `SubmissionDocument(string $internalId, string $json, string $hashHex)` with `static fromJson(string $internalId, string $json)` (hash = sha256 hex), `sizeBytes()`; `SubmissionBatch(list<SubmissionDocument> $documents)` + `count()`, `sizeBytes()`, `toPayload(): array` (`['documents' => [['format'=>'JSON','documentHash'=>…,'codeNumber'=>…,'document'=>base64_encode(json)]]]`); `SubmissionResult(string $submissionUid, array<string,string> $acceptedUuidsByInternalId, array<string, array{code:string,message:string}> $rejectedByInternalId)`; `DocumentSummary(string $uuid, string $internalId, ?string $longId, string $status /*Submitted|Valid|Invalid|Cancelled|Rejected*/, array $errors)`; `SubmissionStatus(string $overallStatus /*in progress|valid|partially valid|invalid*/, list<DocumentSummary> $documents)` + `isFinal(): bool`; `DocumentDetails(string $uuid, string $status, ?string $longId, array $validationErrors /* list<{code,message,target?}> */)`.
  - `LhdnClientFactory::for(Issuer $issuer): LhdnClient` and `forEnvironment(Environment $env): LhdnClient` — `config('lhdn.driver') === 'fake'` → the `FakeLhdnClient` singleton; else `HttpLhdnClient` (Task 3) built with the issuer's environment + credentials.
  - `FakeLhdnClient implements LhdnClient` — in-memory, scriptable: `token()` returns `AccessToken('fake-token', now+1h)`; `submitDocuments()` returns a fresh `SUB-<n>` uid, accepted uuids `<ULID>` per internalId unless scripted `rejectDocument($internalId, $code, $message)`; `getSubmission()` returns `in progress` for the first `$pollsUntilFinal` calls (default 0 → final immediately) then `valid` with `longId = 'L'.uuid` unless `markInvalid($uuid, [errors])` or `markRejected($uuid)` or `markCancelled($uuid)`; `getDocument()` details from the same state; `cancelDocument()` records and marks cancelled; `validateTin()` returns `!in_array($tin, $this->invalidTins)`; scripting: `failNextWith(LhdnException $e, ?string $operation = null)`, `pollsUntilFinal(int)`, `rejectDocument()`, `markInvalid()`, `markRejected()`, `markCancelled()`, `invalidTin(string)`, `calls(): list<array{operation:string, issuer_id:?string, args:array}>`, `reset()`.
  - Pest helper `fakeLhdn(): FakeLhdnClient` = `app(FakeLhdnClient::class)`.

- [ ] **Step 1: Write failing tests `tests/Unit/Lhdn/FakeLhdnClientTest.php`**

```php
<?php

use App\Enums\Environment;
use App\Enums\LhdnMode;
use App\Lhdn\CredentialsResolver;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionDocument;
use App\Lhdn\Fake\FakeLhdnClient;
use App\Lhdn\LhdnClient;
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
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement config, enums, exception, credentials, DTOs**

`config/lhdn.php`
```php
<?php

return [
    'driver' => env('LHDN_DRIVER', 'http'), // http | fake

    'environments' => [
        'sandbox' => [
            'api_base' => env('LHDN_SANDBOX_API_BASE', 'https://preprod-api.myinvois.hasil.gov.my'),
            'identity_base' => env('LHDN_SANDBOX_IDENTITY_BASE', 'https://preprod-api.myinvois.hasil.gov.my'),
            'portal_base' => env('LHDN_SANDBOX_PORTAL_BASE', 'https://preprod.myinvois.hasil.gov.my'),
        ],
        'production' => [
            'api_base' => env('LHDN_PRODUCTION_API_BASE', 'https://api.myinvois.hasil.gov.my'),
            'identity_base' => env('LHDN_PRODUCTION_IDENTITY_BASE', 'https://api.myinvois.hasil.gov.my'),
            'portal_base' => env('LHDN_PRODUCTION_PORTAL_BASE', 'https://myinvois.hasil.gov.my'),
        ],
    ],

    'intermediary' => [
        'sandbox' => ['client_id' => env('LHDN_SANDBOX_CLIENT_ID'), 'client_secret' => env('LHDN_SANDBOX_CLIENT_SECRET')],
        'production' => ['client_id' => env('LHDN_PRODUCTION_CLIENT_ID'), 'client_secret' => env('LHDN_PRODUCTION_CLIENT_SECRET')],
    ],

    'timeout' => (int) env('LHDN_TIMEOUT', 30),
    'token_ttl_margin_seconds' => 60,
    'tin_cache_hours' => 24,

    // per issuer, per minute
    'rate_limits' => ['token' => 12, 'submit' => 100, 'get_submission' => 300, 'get_document' => 60, 'cancel' => 12, 'validate_tin' => 60],

    'circuit_breaker' => ['failure_threshold' => 5, 'cooldown_seconds' => 60],

    'submission' => [
        'max_documents' => 100,
        'max_bytes' => 5 * 1024 * 1024,
        'max_document_bytes' => 300 * 1024,
        'max_attempts' => 8,
        'retry_backoff_seconds' => [30, 60, 120, 300, 600, 1200, 1800, 3600],
    ],

    'poll' => ['backoff_seconds' => [5, 15, 30, 60, 120, 300, 600, 1800, 3600]],
];
```
`.env.example`: add `LHDN_DRIVER=http`, the four intermediary keys (empty), `LHDN_TIMEOUT=30`. `phpunit.xml`: `<env name="LHDN_DRIVER" value="fake"/>`.

`app/Lhdn/LhdnErrorKind.php`
```php
<?php

namespace App\Lhdn;

enum LhdnErrorKind: string
{
    case Transient = 'transient';
    case Auth = 'auth';
    case Terminal = 'terminal';
    case Breaker = 'breaker';
}
```

`app/Lhdn/LhdnException.php`
```php
<?php

namespace App\Lhdn;

use RuntimeException;

class LhdnException extends RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(string $message, public readonly LhdnErrorKind $kind, public readonly ?int $httpStatus = null, public readonly array $payload = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $payload */
    public static function transient(string $message, ?int $status = null, array $payload = []): self
    {
        return new self($message, LhdnErrorKind::Transient, $status, $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function auth(string $message, ?int $status = null, array $payload = []): self
    {
        return new self($message, LhdnErrorKind::Auth, $status, $payload);
    }

    /** @param array<string, mixed> $payload */
    public static function terminal(string $message, ?int $status = null, array $payload = []): self
    {
        return new self($message, LhdnErrorKind::Terminal, $status, $payload);
    }

    public static function breaker(string $message): self
    {
        return new self($message, LhdnErrorKind::Breaker);
    }
}
```

`app/Lhdn/LhdnCredentials.php`
```php
<?php

namespace App\Lhdn;

final class LhdnCredentials
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly ?string $onBehalfOf,
        public readonly string $mode, // intermediary | own
    ) {}

    public function cacheKeyPart(): string
    {
        return $this->mode.':'.sha1($this->clientId.'|'.($this->onBehalfOf ?? ''));
    }
}
```

`app/Lhdn/CredentialsResolver.php`
```php
<?php

namespace App\Lhdn;

use App\Enums\Environment;
use App\Enums\LhdnMode;
use App\Models\Issuer;

class CredentialsResolver
{
    public function for(Issuer $issuer): LhdnCredentials
    {
        if ($issuer->lhdn_mode === LhdnMode::OwnCredentials) {
            $secret = $issuer->secret;
            if ($secret === null || ! $secret->hasCredentials()) {
                throw LhdnException::auth('Issuer LHDN credentials are missing.');
            }

            return new LhdnCredentials((string) $secret->lhdn_client_id, (string) $secret->lhdn_client_secret, null, 'own');
        }
        $base = $this->forIntermediary($issuer->environment);

        return new LhdnCredentials($base->clientId, $base->clientSecret, $issuer->tin, 'intermediary');
    }

    public function forIntermediary(Environment $environment): LhdnCredentials
    {
        $cfg = (array) config("lhdn.intermediary.{$environment->value}", []);
        $id = (string) ($cfg['client_id'] ?? '');
        $secret = (string) ($cfg['client_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw LhdnException::auth("Intermediary LHDN credentials are not configured for {$environment->value}.");
        }

        return new LhdnCredentials($id, $secret, null, 'intermediary');
    }
}
```

DTOs — `app/Lhdn/Data/AccessToken.php`
```php
<?php

namespace App\Lhdn\Data;

use Carbon\CarbonImmutable;

final class AccessToken
{
    public function __construct(public readonly string $token, public readonly CarbonImmutable $expiresAt) {}

    public function isExpired(int $marginSeconds = 0): bool
    {
        return $this->expiresAt->subSeconds($marginSeconds)->isPast();
    }
}
```
`SubmissionDocument.php`
```php
<?php

namespace App\Lhdn\Data;

final class SubmissionDocument
{
    public function __construct(public readonly string $internalId, public readonly string $json, public readonly string $hashHex) {}

    public static function fromJson(string $internalId, string $json): self
    {
        return new self($internalId, $json, hash('sha256', $json));
    }

    public function sizeBytes(): int
    {
        return strlen($this->json);
    }
}
```
`SubmissionBatch.php`
```php
<?php

namespace App\Lhdn\Data;

final class SubmissionBatch
{
    /** @param list<SubmissionDocument> $documents */
    public function __construct(public readonly array $documents) {}

    public function count(): int
    {
        return count($this->documents);
    }

    public function sizeBytes(): int
    {
        return array_sum(array_map(fn (SubmissionDocument $d) => $d->sizeBytes(), $this->documents));
    }

    /** @return array{documents: list<array{format: string, documentHash: string, codeNumber: string, document: string}>} */
    public function toPayload(): array
    {
        return ['documents' => array_map(fn (SubmissionDocument $d) => [
            'format' => 'JSON', 'documentHash' => $d->hashHex, 'codeNumber' => $d->internalId, 'document' => base64_encode($d->json),
        ], $this->documents)];
    }
}
```
`SubmissionResult.php`
```php
<?php

namespace App\Lhdn\Data;

final class SubmissionResult
{
    /**
     * @param  array<string, string>  $acceptedUuidsByInternalId
     * @param  array<string, array{code: string, message: string}>  $rejectedByInternalId
     */
    public function __construct(public readonly string $submissionUid, public readonly array $acceptedUuidsByInternalId, public readonly array $rejectedByInternalId) {}
}
```
`DocumentSummary.php`
```php
<?php

namespace App\Lhdn\Data;

final class DocumentSummary
{
    /** @param list<array{code: string, message: string}> $errors */
    public function __construct(public readonly string $uuid, public readonly string $internalId, public readonly ?string $longId, public readonly string $status, public readonly array $errors = []) {}
}
```
`SubmissionStatus.php`
```php
<?php

namespace App\Lhdn\Data;

final class SubmissionStatus
{
    /** @param list<DocumentSummary> $documents */
    public function __construct(public readonly string $overallStatus, public readonly array $documents) {}

    public function isFinal(): bool
    {
        return strtolower($this->overallStatus) !== 'in progress';
    }
}
```
`DocumentDetails.php`
```php
<?php

namespace App\Lhdn\Data;

final class DocumentDetails
{
    /** @param list<array{code: string, message: string, target?: string}> $validationErrors */
    public function __construct(public readonly string $uuid, public readonly string $status, public readonly ?string $longId, public readonly array $validationErrors = []) {}
}
```

`app/Lhdn/LhdnClient.php`
```php
<?php

namespace App\Lhdn;

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Data\DocumentDetails;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionResult;
use App\Lhdn\Data\SubmissionStatus;
use App\Models\Issuer;

interface LhdnClient
{
    public function token(Issuer $issuer): AccessToken;

    public function submitDocuments(Issuer $issuer, SubmissionBatch $batch): SubmissionResult;

    public function getSubmission(Issuer $issuer, string $submissionUid): SubmissionStatus;

    public function getDocument(Issuer $issuer, string $uuid): DocumentDetails;

    public function cancelDocument(Issuer $issuer, string $uuid, string $reason): void;

    public function validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool;
}
```

- [ ] **Step 4: FakeLhdnClient + factory + bindings**

`app/Lhdn/Fake/FakeLhdnClient.php`
```php
<?php

namespace App\Lhdn\Fake;

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Data\DocumentDetails;
use App\Lhdn\Data\DocumentSummary;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionResult;
use App\Lhdn\Data\SubmissionStatus;
use App\Lhdn\LhdnClient;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * In-memory LHDN double for tests. Scriptable per operation; records every call.
 */
class FakeLhdnClient implements LhdnClient
{
    /** @var list<array{operation: string, issuer_id: ?string, args: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array{code: string, message: string}> */
    private array $rejections = [];

    /** @var array<string, array{internalId: string, status: string, longId: ?string, errors: list<array{code: string, message: string}>}> */
    private array $documents = [];

    /** @var array<string, list<string>> submissionUid => uuids */
    private array $submissions = [];

    /** @var array<string, int> */
    private array $pollCounts = [];

    private int $pollsUntilFinal = 0;

    private int $submissionCounter = 0;

    /** @var list<string> */
    private array $invalidTins = [];

    /** @var list<array{exception: LhdnException, operation: ?string}> */
    private array $failures = [];

    public function token(Issuer $issuer): AccessToken
    {
        $this->record('token', $issuer, []);
        $this->maybeFail('token');

        return new AccessToken('fake-token-'.$issuer->id, CarbonImmutable::now()->addHour());
    }

    public function submitDocuments(Issuer $issuer, SubmissionBatch $batch): SubmissionResult
    {
        $this->record('submit', $issuer, ['count' => $batch->count()]);
        $this->maybeFail('submit');
        $uid = 'SUB-'.(++$this->submissionCounter);
        $accepted = [];
        $rejected = [];
        foreach ($batch->documents as $doc) {
            if (isset($this->rejections[$doc->internalId])) {
                $rejected[$doc->internalId] = $this->rejections[$doc->internalId];

                continue;
            }
            $uuid = (string) Str::ulid();
            $accepted[$doc->internalId] = $uuid;
            $this->documents[$uuid] = ['internalId' => $doc->internalId, 'status' => 'Submitted', 'longId' => null, 'errors' => []];
            $this->submissions[$uid][] = $uuid;
        }

        return new SubmissionResult($uid, $accepted, $rejected);
    }

    public function getSubmission(Issuer $issuer, string $submissionUid): SubmissionStatus
    {
        $this->record('get_submission', $issuer, ['submission_uid' => $submissionUid]);
        $this->maybeFail('get_submission');
        $this->pollCounts[$submissionUid] = ($this->pollCounts[$submissionUid] ?? 0) + 1;
        $final = $this->pollCounts[$submissionUid] > $this->pollsUntilFinal;
        $summaries = [];
        $anyInvalid = false;
        $allValid = true;
        foreach ($this->submissions[$submissionUid] ?? [] as $uuid) {
            $d = $this->documents[$uuid];
            if ($final && $d['status'] === 'Submitted') {
                $d['status'] = 'Valid';
                $d['longId'] = 'L'.$uuid;
                $this->documents[$uuid] = $d;
            }
            $anyInvalid = $anyInvalid || $d['status'] === 'Invalid';
            $allValid = $allValid && $d['status'] === 'Valid';
            $summaries[] = new DocumentSummary($uuid, $d['internalId'], $d['longId'], $d['status'], $d['errors']);
        }
        $overall = ! $final ? 'in progress' : ($allValid ? 'valid' : ($anyInvalid && count($summaries) > 1 ? 'partially valid' : 'invalid'));

        return new SubmissionStatus($overall, $summaries);
    }

    public function getDocument(Issuer $issuer, string $uuid): DocumentDetails
    {
        $this->record('get_document', $issuer, ['uuid' => $uuid]);
        $this->maybeFail('get_document');
        $d = $this->documents[$uuid] ?? throw LhdnException::terminal("Unknown document {$uuid}", 404);

        return new DocumentDetails($uuid, $d['status'], $d['longId'], $d['errors']);
    }

    public function cancelDocument(Issuer $issuer, string $uuid, string $reason): void
    {
        $this->record('cancel', $issuer, ['uuid' => $uuid, 'reason' => $reason]);
        $this->maybeFail('cancel');
        if (isset($this->documents[$uuid])) {
            $this->documents[$uuid]['status'] = 'Cancelled';
        }
    }

    public function validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool
    {
        $this->record('validate_tin', $issuer, ['tin' => $tin, 'id_type' => $idType, 'id_value' => $idValue, 'environment' => $environment->value]);
        $this->maybeFail('validate_tin');

        return ! in_array($tin, $this->invalidTins, true);
    }

    // ---- scripting ----

    public function failNextWith(LhdnException $e, ?string $operation = null): void
    {
        $this->failures[] = ['exception' => $e, 'operation' => $operation];
    }

    public function pollsUntilFinal(int $n): void
    {
        $this->pollsUntilFinal = $n;
    }

    public function rejectDocument(string $internalId, string $code, string $message): void
    {
        $this->rejections[$internalId] = ['code' => $code, 'message' => $message];
    }

    /** @param list<array{code: string, message: string}> $errors */
    public function markInvalid(string $uuid, array $errors): void
    {
        $this->documents[$uuid]['status'] = 'Invalid';
        $this->documents[$uuid]['errors'] = $errors;
    }

    public function markRejected(string $uuid): void
    {
        $this->documents[$uuid]['status'] = 'Rejected';
    }

    public function markCancelled(string $uuid): void
    {
        $this->documents[$uuid]['status'] = 'Cancelled';
    }

    public function invalidTin(string $tin): void
    {
        $this->invalidTins[] = $tin;
    }

    /** @return list<array{operation: string, issuer_id: ?string, args: array<string, mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->calls = $this->rejections = $this->documents = $this->submissions = $this->pollCounts = $this->invalidTins = $this->failures = [];
        $this->pollsUntilFinal = 0;
        $this->submissionCounter = 0;
    }

    /** @param array<string, mixed> $args */
    private function record(string $operation, ?Issuer $issuer, array $args): void
    {
        $this->calls[] = ['operation' => $operation, 'issuer_id' => $issuer?->id, 'args' => $args];
    }

    private function maybeFail(string $operation): void
    {
        foreach ($this->failures as $i => $f) {
            if ($f['operation'] === null || $f['operation'] === $operation) {
                unset($this->failures[$i]);
                $this->failures = array_values($this->failures);
                throw $f['exception'];
            }
        }
    }
}
```

`app/Lhdn/LhdnClientFactory.php`
```php
<?php

namespace App\Lhdn;

use App\Enums\Environment;
use App\Lhdn\Fake\FakeLhdnClient;
use App\Lhdn\Http\HttpLhdnClient;
use App\Models\Issuer;

class LhdnClientFactory
{
    public function __construct(private readonly CredentialsResolver $credentials) {}

    public function for(Issuer $issuer): LhdnClient
    {
        if ($this->isFake()) {
            return app(FakeLhdnClient::class);
        }

        return HttpLhdnClient::make($issuer->environment, $this->credentials->for($issuer));
    }

    public function forEnvironment(Environment $environment): LhdnClient
    {
        if ($this->isFake()) {
            return app(FakeLhdnClient::class);
        }

        return HttpLhdnClient::make($environment, $this->credentials->forIntermediary($environment));
    }

    private function isFake(): bool
    {
        return config('lhdn.driver') === 'fake';
    }
}
```
`HttpLhdnClient` is created in Task 3; for Task 2 to compile, create a minimal placeholder `app/Lhdn/Http/HttpLhdnClient.php` with `public static function make(Environment $environment, LhdnCredentials $credentials): self` and the interface methods each throwing `new \LogicException('HttpLhdnClient is implemented in Plan 3 Task 3')` — Task 3 replaces the whole file.

`AppServiceProvider::register()`: `$this->app->singleton(FakeLhdnClient::class);`. `tests/Pest.php`: `function fakeLhdn(): \App\Lhdn\Fake\FakeLhdnClient { return app(\App\Lhdn\Fake\FakeLhdnClient::class); }` and in the Feature/Unit `beforeEach` hook? Simpler: the singleton is per app instance; Laravel's TestCase creates a fresh app per test, so no reset needed.

- [ ] **Step 5: Run tests** — `vendor/bin/pest tests/Unit/Lhdn/FakeLhdnClientTest.php` → pass; `composer check` green.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(lhdn): client contract, DTOs, exceptions, config, credentials resolver, factory and FakeLhdnClient"
```

---
### Task 3: `HttpLhdnClient`, `TokenProvider`, `AttemptRecorder`, `CircuitBreaker`, `LhdnRateLimiter`

**Files:**
- Create: `app/Lhdn/Http/HttpLhdnClient.php` (replace placeholder), `app/Lhdn/Http/TokenProvider.php`, `app/Lhdn/Http/AttemptRecorder.php`, `app/Lhdn/CircuitBreaker.php`, `app/Lhdn/LhdnRateLimiter.php`, `tests/Unit/Lhdn/HttpLhdnClientTest.php`, `tests/Unit/Lhdn/TokenProviderTest.php`, `tests/Unit/Lhdn/CircuitBreakerTest.php`

**Interfaces:**
- Consumes: Task 2 contract/DTOs/exceptions/config; `SubmissionAttempt` (Task 1); `TenantContext`.
- Produces:
  - `TokenProvider::get(Environment $env, LhdnCredentials $creds, callable $fetch /* fn(): AccessToken */): AccessToken` — cache key `lhdn:token:{env}:{creds->cacheKeyPart()}`, TTL = expires − `token_ttl_margin_seconds`; single-flight via `Cache::lock($key.':lock', 10)->block(5, …)`; `forget(Environment, LhdnCredentials)`.
  - `AttemptRecorder::record(Issuer $issuer, string $operation, ?string $documentId, ?string $submissionUid, ?int $httpStatus, ?array $request, ?array $response, ?LhdnException $error, int $durationMs): SubmissionAttempt` — redacts: never stores `Authorization`, `client_secret`, token values, or full base64 document bodies (stores `documents: [{codeNumber, documentHash, bytes}]` instead).
  - `CircuitBreaker` (cache-based, per environment): `isOpen(Environment): bool`, `recordFailure(Environment): void` (opens after `failure_threshold` consecutive failures for `cooldown_seconds`), `recordSuccess(Environment): void` (resets), `guard(Environment): void` (throws `LhdnException::breaker(...)` when open).
  - `LhdnRateLimiter::attempt(Issuer $issuer, string $operation, callable $fn): mixed` — `RateLimiter::attempt("lhdn:{op}:{issuerId}", config('lhdn.rate_limits.{op}'), $fn, 60)`; when limited throws `LhdnException::transient("LHDN {op} rate budget exhausted for issuer", 429)`.
  - `HttpLhdnClient::make(Environment, LhdnCredentials): self` — uses `Http::baseUrl(api_base)->timeout(config lhdn.timeout)->acceptJson()`; endpoints:
    - token: `POST {identity_base}/connect/token` form `grant_type=client_credentials&client_id&client_secret&scope=InvoicingAPI`, header `onbehalfof: {tin}` when set; response `{access_token, expires_in}` → `AccessToken`.
    - submit: `POST /api/v1.0/documentsubmissions` JSON `batch->toPayload()`; response `{submissionUid, acceptedDocuments:[{uuid, invoiceCodeNumber}], rejectedDocuments:[{invoiceCodeNumber, error:{code, message}}]}`.
    - get submission: `GET /api/v1.0/documentsubmissions/{uid}?pageNo=1&pageSize=100` → `{overallStatus, documentSummary:[{uuid, internalId, longId, status, ...}]}`.
    - get document: `GET /api/v1.0/documents/{uuid}/details` → `{uuid, status, longId, validationResults:{status, validationSteps:[{name, status, error:{code, message, target?}}]}}` → errors = failed steps.
    - cancel: `PUT /api/v1.0/documents/state/{uuid}/state` JSON `{status: 'cancelled', reason}`; 200 → ok.
    - validate TIN: `GET /api/v1.0/taxpayer/validate/{tin}?idType={idType}&idValue={idValue}` → 200 true, 404 false (other statuses → exception per classification).
    Every call: breaker guard → rate limiter → bearer token (via TokenProvider, except the token call itself) → HTTP → classify → record attempt (success or failure) → on `Auth` error `TokenProvider::forget` + breaker unaffected; on `Transient` `recordFailure`; on success `recordSuccess`.
    Classification: `ConnectionException`/timeout → Transient; 429/5xx → Transient; 401/403 → Auth; other 4xx → Terminal (message from body `error.message`/`message`/`title` fallback `HTTP {status}`).
    The `Issuer` passed to each method is only used for recording attempts + rate-limit keys (credentials/env are fixed at construction).

- [ ] **Step 1: Write failing tests**

`tests/Unit/Lhdn/CircuitBreakerTest.php`
```php
<?php

use App\Enums\Environment;
use App\Lhdn\CircuitBreaker;
use App\Lhdn\LhdnException;

it('opens after the failure threshold and closes after cooldown', function () {
    config(['lhdn.circuit_breaker' => ['failure_threshold' => 2, 'cooldown_seconds' => 60]]);
    $cb = new CircuitBreaker;
    $cb->recordFailure(Environment::Sandbox);
    expect($cb->isOpen(Environment::Sandbox))->toBeFalse();
    $cb->recordFailure(Environment::Sandbox);
    expect($cb->isOpen(Environment::Sandbox))->toBeTrue()->and($cb->isOpen(Environment::Production))->toBeFalse();
    expect(fn () => $cb->guard(Environment::Sandbox))->toThrow(LhdnException::class);
    $this->travel(61)->seconds();
    expect($cb->isOpen(Environment::Sandbox))->toBeFalse();
    $cb->recordFailure(Environment::Sandbox);
    $cb->recordSuccess(Environment::Sandbox);
    $cb->recordFailure(Environment::Sandbox);
    expect($cb->isOpen(Environment::Sandbox))->toBeFalse(); // success reset the streak
});
```

`tests/Unit/Lhdn/TokenProviderTest.php`
```php
<?php

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Http\TokenProvider;
use App\Lhdn\LhdnCredentials;
use Carbon\CarbonImmutable;

it('caches tokens per environment + credentials and refreshes when near expiry', function () {
    $tp = new TokenProvider;
    $creds = new LhdnCredentials('id', 'secret', 'C123', 'intermediary');
    $calls = 0;
    $fetch = function () use (&$calls) { $calls++; return new AccessToken('t'.$calls, CarbonImmutable::now()->addSeconds(3600)); };
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t1');
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t1');
    expect($tp->get(Environment::Production, $creds, $fetch)->token)->toBe('t2');
    $other = new LhdnCredentials('id', 'secret', 'C999', 'intermediary');
    expect($tp->get(Environment::Sandbox, $other, $fetch)->token)->toBe('t3');
    $tp->forget(Environment::Sandbox, $creds);
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t4');
    $this->travel(3550)->seconds(); // inside the 60s margin
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t5');
});
```

`tests/Unit/Lhdn/HttpLhdnClientTest.php` (Feature-style DB needed for attempts → put under `tests/Feature/Lhdn/HttpLhdnClientTest.php` instead; `RefreshDatabase` applies via Pest.php)
```php
<?php

use App\Enums\Environment;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionDocument;
use App\Lhdn\Http\HttpLhdnClient;
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
    Http::fake(['https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]), 'https://lhdn.test/*' => Http::response(['error' => 'down'], 502)]);
    expect(fn () => $this->client->getSubmission($this->issuer, 'X'))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Transient));
    expect(fn () => $this->client->getSubmission($this->issuer, 'X'))->toThrow(fn (LhdnException $e) => expect($e->kind)->toBe(LhdnErrorKind::Breaker));
    app(\App\Lhdn\CircuitBreaker::class)->recordSuccess(Environment::Sandbox);
    Http::fake(['https://lhdn.test/connect/token' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]), 'https://lhdn.test/api/v1.0/taxpayer/*' => Http::response(null, 200)]);
    $this->client->validateTin(Environment::Sandbox, 'C1', 'BRN', '1', $this->issuer);
    expect(fn () => $this->client->validateTin(Environment::Sandbox, 'C1', 'BRN', '1', $this->issuer))->toThrow(fn (LhdnException $e) => expect($e->httpStatus)->toBe(429));
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Lhdn/CircuitBreakerTest.php tests/Unit/Lhdn/TokenProviderTest.php tests/Feature/Lhdn/HttpLhdnClientTest.php` → FAIL.

- [ ] **Step 3: Implement**

`app/Lhdn/CircuitBreaker.php`
```php
<?php

namespace App\Lhdn;

use App\Enums\Environment;
use Illuminate\Support\Facades\Cache;

class CircuitBreaker
{
    public function isOpen(Environment $env): bool
    {
        return Cache::has($this->openKey($env));
    }

    public function guard(Environment $env): void
    {
        if ($this->isOpen($env)) {
            throw LhdnException::breaker("LHDN circuit breaker is open for {$env->value}; retry after cooldown.");
        }
    }

    public function recordFailure(Environment $env): void
    {
        $threshold = (int) config('lhdn.circuit_breaker.failure_threshold', 5);
        $cooldown = (int) config('lhdn.circuit_breaker.cooldown_seconds', 60);
        $failures = (int) Cache::increment($this->countKey($env));
        if ($failures === 1) {
            Cache::put($this->countKey($env), 1, now()->addSeconds($cooldown * 2));
        }
        if ($failures >= $threshold) {
            Cache::put($this->openKey($env), true, now()->addSeconds($cooldown));
            Cache::forget($this->countKey($env));
        }
    }

    public function recordSuccess(Environment $env): void
    {
        Cache::forget($this->countKey($env));
        Cache::forget($this->openKey($env));
    }

    private function openKey(Environment $env): string
    {
        return "lhdn:breaker:open:{$env->value}";
    }

    private function countKey(Environment $env): string
    {
        return "lhdn:breaker:failures:{$env->value}";
    }
}
```
(`Cache::increment` on a missing key creates it with no TTL on the array/redis stores — the explicit `put` on first failure sets a TTL. On the array store `increment` returns int.)

`app/Lhdn/LhdnRateLimiter.php`
```php
<?php

namespace App\Lhdn;

use App\Models\Issuer;
use Illuminate\Support\Facades\RateLimiter;

class LhdnRateLimiter
{
    /** @template T @param callable(): T $fn @return T */
    public function attempt(Issuer $issuer, string $operation, callable $fn): mixed
    {
        $limit = (int) config("lhdn.rate_limits.{$operation}", 60);
        $key = "lhdn:{$operation}:{$issuer->id}";
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw LhdnException::transient("LHDN {$operation} rate budget exhausted for issuer {$issuer->id}.", 429);
        }
        RateLimiter::hit($key, 60);

        return $fn();
    }
}
```

`app/Lhdn/Http/TokenProvider.php`
```php
<?php

namespace App\Lhdn\Http;

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\LhdnCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class TokenProvider
{
    /** @param callable(): AccessToken $fetch */
    public function get(Environment $env, LhdnCredentials $creds, callable $fetch): AccessToken
    {
        $key = $this->key($env, $creds);
        $margin = (int) config('lhdn.token_ttl_margin_seconds', 60);
        if ($cached = $this->read($key, $margin)) {
            return $cached;
        }

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $margin, $fetch): AccessToken {
            if ($cached = $this->read($key, $margin)) {
                return $cached;
            }
            $token = $fetch();
            $ttl = max(1, $token->expiresAt->getTimestamp() - time() - $margin);
            Cache::put($key, ['token' => $token->token, 'expires_at' => $token->expiresAt->getTimestamp()], $ttl);

            return $token;
        });
    }

    public function forget(Environment $env, LhdnCredentials $creds): void
    {
        Cache::forget($this->key($env, $creds));
    }

    private function read(string $key, int $margin): ?AccessToken
    {
        /** @var array{token: string, expires_at: int}|null $raw */
        $raw = Cache::get($key);
        if ($raw === null) {
            return null;
        }
        $token = new AccessToken($raw['token'], CarbonImmutable::createFromTimestamp($raw['expires_at']));

        return $token->isExpired($margin) ? null : $token;
    }

    private function key(Environment $env, LhdnCredentials $creds): string
    {
        return "lhdn:token:{$env->value}:{$creds->cacheKeyPart()}";
    }
}
```

`app/Lhdn/Http/AttemptRecorder.php`
```php
<?php

namespace App\Lhdn\Http;

use App\Enums\Environment;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;

class AttemptRecorder
{
    /**
     * @param  array<string, mixed>|null  $request  already-redacted request summary
     * @param  array<string, mixed>|null  $response
     */
    public function record(Issuer $issuer, Environment $environment, string $operation, ?string $documentId, ?string $submissionUid, ?int $httpStatus, ?array $request, ?array $response, ?LhdnException $error, int $durationMs): SubmissionAttempt
    {
        return SubmissionAttempt::create([
            'issuer_id' => $issuer->id,
            'document_id' => $documentId,
            'submission_uid' => $submissionUid,
            'operation' => $operation,
            'environment' => $environment,
            'http_status' => $httpStatus,
            'request' => $request,
            'response' => $response === null ? null : self::truncate($response),
            'error_kind' => $error?->kind->value,
            'error_message' => $error ? mb_substr($error->getMessage(), 0, 500) : null,
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private static function truncate(array $response): array
    {
        $json = json_encode($response);
        if ($json !== false && strlen($json) > 65535) {
            return ['_truncated' => true, 'preview' => substr($json, 0, 65000)];
        }

        return $response;
    }
}
```

`app/Lhdn/Http/HttpLhdnClient.php`
```php
<?php

namespace App\Lhdn\Http;

use App\Enums\Environment;
use App\Lhdn\CircuitBreaker;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Data\DocumentDetails;
use App\Lhdn\Data\DocumentSummary;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionResult;
use App\Lhdn\Data\SubmissionStatus;
use App\Lhdn\LhdnClient;
use App\Lhdn\LhdnCredentials;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Lhdn\LhdnRateLimiter;
use App\Models\Issuer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpLhdnClient implements LhdnClient
{
    public function __construct(
        private readonly Environment $environment,
        private readonly LhdnCredentials $credentials,
        private readonly TokenProvider $tokens,
        private readonly AttemptRecorder $attempts,
        private readonly CircuitBreaker $breaker,
        private readonly LhdnRateLimiter $limiter,
    ) {}

    public static function make(Environment $environment, LhdnCredentials $credentials): self
    {
        return new self($environment, $credentials, app(TokenProvider::class), app(AttemptRecorder::class), app(CircuitBreaker::class), app(LhdnRateLimiter::class));
    }

    public function token(Issuer $issuer): AccessToken
    {
        return $this->tokens->get($this->environment, $this->credentials, fn () => $this->fetchToken($issuer));
    }

    public function submitDocuments(Issuer $issuer, SubmissionBatch $batch): SubmissionResult
    {
        $summary = ['documents' => array_map(fn ($d) => ['codeNumber' => $d->internalId, 'documentHash' => $d->hashHex, 'bytes' => $d->sizeBytes()], $batch->documents)];
        $data = $this->call($issuer, 'submit', null, null, $summary, fn (PendingRequest $http) => $http->post('/api/v1.0/documentsubmissions', $batch->toPayload()));
        $accepted = [];
        foreach ((array) ($data['acceptedDocuments'] ?? []) as $a) {
            $accepted[(string) $a['invoiceCodeNumber']] = (string) $a['uuid'];
        }
        $rejected = [];
        foreach ((array) ($data['rejectedDocuments'] ?? []) as $r) {
            $rejected[(string) $r['invoiceCodeNumber']] = ['code' => (string) ($r['error']['code'] ?? 'rejected'), 'message' => (string) ($r['error']['message'] ?? 'Rejected by LHDN')];
        }

        return new SubmissionResult((string) ($data['submissionUid'] ?? ''), $accepted, $rejected);
    }

    public function getSubmission(Issuer $issuer, string $submissionUid): SubmissionStatus
    {
        $data = $this->call($issuer, 'get_submission', null, $submissionUid, ['submission_uid' => $submissionUid], fn (PendingRequest $http) => $http->get("/api/v1.0/documentsubmissions/{$submissionUid}", ['pageNo' => 1, 'pageSize' => 100]));
        $docs = [];
        foreach ((array) ($data['documentSummary'] ?? []) as $d) {
            $docs[] = new DocumentSummary((string) $d['uuid'], (string) ($d['internalId'] ?? ''), isset($d['longId']) && $d['longId'] !== '' ? (string) $d['longId'] : null, (string) ($d['status'] ?? 'Submitted'));
        }

        return new SubmissionStatus((string) ($data['overallStatus'] ?? 'in progress'), $docs);
    }

    public function getDocument(Issuer $issuer, string $uuid): DocumentDetails
    {
        $data = $this->call($issuer, 'get_document', null, null, ['uuid' => $uuid], fn (PendingRequest $http) => $http->get("/api/v1.0/documents/{$uuid}/details"));
        $errors = [];
        foreach ((array) data_get($data, 'validationResults.validationSteps', []) as $step) {
            if (strtolower((string) ($step['status'] ?? '')) === 'invalid') {
                $errors[] = array_filter([
                    'code' => (string) data_get($step, 'error.code', 'invalid'),
                    'message' => (string) data_get($step, 'error.message', (string) ($step['name'] ?? 'Validation failed')),
                    'target' => data_get($step, 'error.target'),
                ], fn ($v) => $v !== null);
            }
        }

        return new DocumentDetails((string) ($data['uuid'] ?? $uuid), (string) ($data['status'] ?? ''), isset($data['longId']) && $data['longId'] !== '' ? (string) $data['longId'] : null, array_values($errors));
    }

    public function cancelDocument(Issuer $issuer, string $uuid, string $reason): void
    {
        $this->call($issuer, 'cancel', null, null, ['uuid' => $uuid, 'reason' => $reason], fn (PendingRequest $http) => $http->put("/api/v1.0/documents/state/{$uuid}/state", ['status' => 'cancelled', 'reason' => $reason]));
    }

    public function validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool
    {
        $issuerForRecord = $issuer ?? throw new \InvalidArgumentException('HttpLhdnClient::validateTin requires an issuer for attempt recording; use the intermediary client with a system issuer or pass the acting issuer.');
        try {
            $this->call($issuerForRecord, 'validate_tin', null, null, ['tin' => $tin, 'id_type' => $idType], fn (PendingRequest $http) => $http->get("/api/v1.0/taxpayer/validate/{$tin}", ['idType' => $idType, 'idValue' => $idValue]));

            return true;
        } catch (LhdnException $e) {
            if ($e->kind === LhdnErrorKind::Terminal && $e->httpStatus === 404) {
                return false;
            }
            throw $e;
        }
    }

    // ---- internals ----

    private function fetchToken(Issuer $issuer): AccessToken
    {
        $start = hrtime(true);
        $request = ['client_id' => substr($this->credentials->clientId, 0, 4).'***', 'scope' => 'InvoicingAPI', 'onbehalfof' => $this->credentials->onBehalfOf];
        try {
            $this->breaker->guard($this->environment);
            $response = $this->limiter->attempt($issuer, 'token', fn () => $this->identity()->asForm()->post('/connect/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->credentials->clientId,
                'client_secret' => $this->credentials->clientSecret,
                'scope' => 'InvoicingAPI',
            ]));
            $data = $this->classify($response);
            $token = new AccessToken((string) $data['access_token'], CarbonImmutable::now()->addSeconds((int) ($data['expires_in'] ?? 3600)));
            $this->attempts->record($issuer, $this->environment, 'token', null, null, $response->status(), $request, ['expires_in' => $data['expires_in'] ?? null], null, $this->ms($start));
            $this->breaker->recordSuccess($this->environment);

            return $token;
        } catch (ConnectionException $e) {
            $ex = LhdnException::transient('LHDN identity service unreachable: '.$e->getMessage());
            $this->attempts->record($issuer, $this->environment, 'token', null, null, null, $request, null, $ex, $this->ms($start));
            $this->breaker->recordFailure($this->environment);
            throw $ex;
        } catch (LhdnException $e) {
            $this->attempts->record($issuer, $this->environment, 'token', null, null, $e->httpStatus, $request, $e->payload, $e, $this->ms($start));
            if ($e->kind === LhdnErrorKind::Transient) {
                $this->breaker->recordFailure($this->environment);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>|null  $requestSummary
     * @param  callable(PendingRequest): Response  $send
     * @return array<string, mixed>
     */
    private function call(Issuer $issuer, string $operation, ?string $documentId, ?string $submissionUid, ?array $requestSummary, callable $send): array
    {
        $start = hrtime(true);
        try {
            $this->breaker->guard($this->environment);
            $token = $this->token($issuer);
            $response = $this->limiter->attempt($issuer, $operation, fn () => $send($this->api()->withToken($token->token)));
            $data = $this->classify($response);
            $uid = $submissionUid ?? (isset($data['submissionUid']) ? (string) $data['submissionUid'] : null);
            $this->attempts->record($issuer, $this->environment, $operation, $documentId, $uid, $response->status(), $requestSummary, $data, null, $this->ms($start));
            $this->breaker->recordSuccess($this->environment);

            return $data;
        } catch (ConnectionException $e) {
            $ex = LhdnException::transient('LHDN API unreachable: '.$e->getMessage());
            $this->attempts->record($issuer, $this->environment, $operation, $documentId, $submissionUid, null, $requestSummary, null, $ex, $this->ms($start));
            $this->breaker->recordFailure($this->environment);
            throw $ex;
        } catch (LhdnException $e) {
            $this->attempts->record($issuer, $this->environment, $operation, $documentId, $submissionUid, $e->httpStatus, $requestSummary, $e->payload, $e, $this->ms($start));
            if ($e->kind === LhdnErrorKind::Auth) {
                $this->tokens->forget($this->environment, $this->credentials);
            }
            if ($e->kind === LhdnErrorKind::Transient) {
                $this->breaker->recordFailure($this->environment);
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function classify(Response $response): array
    {
        $status = $response->status();
        $body = $response->json();
        $payload = is_array($body) ? $body : ['raw' => mb_substr((string) $response->body(), 0, 2000)];
        if ($response->successful()) {
            return is_array($body) ? $body : [];
        }
        $message = (string) (data_get($payload, 'error.message') ?? data_get($payload, 'message') ?? data_get($payload, 'title') ?? data_get($payload, 'error') ?? "HTTP {$status}");
        if (is_array($message)) { // some LHDN errors nest arrays under "error"
            $message = (string) json_encode($message);
        }
        throw match (true) {
            $status === 401 || $status === 403 => LhdnException::auth("LHDN rejected the credentials ({$status}): {$message}", $status, $payload),
            $status === 429 || $status >= 500 => LhdnException::transient("LHDN temporarily unavailable ({$status}): {$message}", $status, $payload),
            default => LhdnException::terminal("LHDN rejected the request ({$status}): {$message}", $status, $payload),
        };
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl((string) config("lhdn.environments.{$this->environment->value}.api_base"))->timeout((int) config('lhdn.timeout', 30))->acceptJson();
    }

    private function identity(): PendingRequest
    {
        $req = Http::baseUrl((string) config("lhdn.environments.{$this->environment->value}.identity_base"))->timeout((int) config('lhdn.timeout', 30))->acceptJson();

        return $this->credentials->onBehalfOf !== null ? $req->withHeaders(['onbehalfof' => $this->credentials->onBehalfOf]) : $req;
    }

    private function ms(int $startNs): int
    {
        return (int) ((hrtime(true) - $startNs) / 1_000_000);
    }
}
```
Notes: `validateTin` without an issuer is used by `POST /tin/validate` when `issuer_id` is omitted (Task 6) — Task 6 passes a "system" issuer? No: Task 6 requires the caller to name an issuer OR falls back to any active issuer of the tenant for attempt recording; if none exists, Task 6 returns 409 `issuer_required`. Keep that rule (documented in Task 6). The `Issuer` passed to `call()` is used only for recording + rate keys.
`classify()` for the 404 TIN case: `terminal` with `httpStatus 404` → `validateTin` maps to `false` (attempt still recorded with error_kind terminal — acceptable).

- [ ] **Step 4: Run tests** — pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(lhdn): HttpLhdnClient with token cache, attempt recording, rate budget and circuit breaker"
```

---

### Task 4: UBL 2.1 JSON builder (+ golden files)

**Files:**
- Create: `app/Lhdn/Ubl/UblDocumentBuilder.php`, `app/Lhdn/Ubl/UblParty.php`, `tests/Unit/Lhdn/UblDocumentBuilderTest.php`, `tests/Fixtures/ubl/invoice-myr.json`, `tests/Fixtures/ubl/credit-note.json`, `tests/Fixtures/ubl/self-billed-usd.json`, `tests/Support/Golden.php`
- Modify: `tests/Pest.php` (load `tests/Support/Golden.php` helper)

**Interfaces:**
- Consumes: `Document` (+ `lines`, `issuer`, `buyer_snapshot`, `original_lhdn_uuid`/`originalDocument`), `DocumentType::lhdnCode()`, `Money`.
- Produces:
  - `UblDocumentBuilder::build(Document $document): array` — returns the LHDN JSON document array (`_D`, `_A`, `_B`, and the root key `Invoice` for ALL types — LHDN uses `Invoice` as the root element for every document type, the type is in `InvoiceTypeCode`) **without** `UBLExtensions`/`Signature` (the signer adds them). `InvoiceTypeCode` `listVersionID` = `1.1`. Deterministic: identical input → identical array.
  - `UblParty::supplier(Issuer $issuer): array` and `UblParty::buyer(array $snapshot): array` build `AccountingSupplierParty`/`AccountingCustomerParty` blocks.
  - Field mapping (per LHDN SDK v1.1):
    - `Invoice[0].ID` = `[{_: lhdn_internal_id ?? document id}]`; `IssueDate` = issue_date `Y-m-d`; `IssueTime` = `created_at` in UTC `H:i:s\Z`; `InvoiceTypeCode` = `[{_: type->lhdnCode(), listVersionID: '1.1'}]`; `DocumentCurrencyCode` = currency; `TaxCurrencyCode` = `MYR`; `TaxExchangeRate` (only when currency ≠ MYR) = `[{SourceCurrencyCode:[{_:currency}], TargetCurrencyCode:[{_:'MYR'}], CalculationRate:[{_: (float) exchange_rate}]}]`.
    - Notes (`requiresOriginalRef()`): `BillingReference` = `[{InvoiceDocumentReference: [{ID:[{_: original lhdn_internal_id or original_lhdn_uuid}], UUID:[{_: original lhdn_uuid}]}]}]` (UUID taken from `originalDocument->lhdn_uuid` or `original_lhdn_uuid`; omit `UUID` when unknown).
    - Supplier: `Party.IndustryClassificationCode [{_: msic_code, name: business_activity_description}]`, `PartyIdentification` = `[{ID:[{_: tin, schemeID:'TIN'}]}, {ID:[{_: id_number, schemeID: id_type->value}]}]` + SST (`schemeID:'SST'`) when set (`'NA'` otherwise is NOT emitted) + TTX when set; `PostalAddress {CityName, PostalZone, CountrySubentityCode, AddressLine[Line…] (line1..3), Country.IdentificationCode {_: country_code, listID:'ISO3166-1', listAgencyID:'6'}}`; `PartyLegalEntity.RegistrationName`; `Contact {Telephone, ElectronicMail}`.
    - Buyer from snapshot: same shape; for general public: TIN `EI00000000010`, `BRN`/`NA`, name `General Public`, address lines `['NA']`, city `'NA'`, postcode `'NA'`, state `'17'`, country `MYS`, phone `'NA'`, email omitted.
    - Lines: `InvoiceLine[]` each `ID [{_: position}]`, `InvoicedQuantity [{_: qty (float, up to 4dp), unitCode}]`, `LineExtensionAmount [{_: subtotal, currencyID}]`, `AllowanceCharge` when discount > 0: `[{ChargeIndicator:[{_:false}], AllowanceChargeReason:[{_:'Discount'}], Amount:[{_: discount, currencyID}]}]`, `TaxTotal [{TaxAmount:[{_: tax}], TaxSubtotal:[{TaxableAmount:[{_: subtotal}], TaxAmount:[{_: tax}], TaxCategory:[{ID:[{_: tax_type}], Percent:[{_: rate}] (when rate set), TaxExemptionReason:[{_: reason}] (when tax_type E), TaxScheme:[{ID:[{_:'OTH', schemeID:'UN/ECE 5153', schemeAgencyID:'6'}]}]}]}]}]`, `Item [{CommodityClassification:[{ItemClassificationCode:[{_: classification_code, listID:'CLASS'}]}], Description:[{_: description}]}]`, `Price [{PriceAmount:[{_: unit_price}]}]`, `ItemPriceExtension [{Amount:[{_: gross (qty×price), currencyID}]}]`.
    - Document `TaxTotal [{TaxAmount:[{_: tax_total}], TaxSubtotal: one per distinct (tax_type, rate) with summed taxable/tax}]`; `LegalMonetaryTotal [{LineExtensionAmount:[{_: total_excluding_tax}], TaxExclusiveAmount:[{_: total_excluding_tax}], TaxInclusiveAmount:[{_: total_including_tax}], AllowanceTotalAmount:[{_: discount_total}], PayableAmount:[{_: total_payable}]}]` — all with `currencyID`.
    - Numbers: emit as PHP floats from the decimal strings (`(float) $value`) — JSON then renders `22.26`; quantities may carry 4 dp.
  - Golden helper `tests/Support/Golden.php`: `function assertMatchesGolden(string $name, array $actual): void` — compares `json_encode($actual, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)` to `tests/Fixtures/ubl/{name}.json`; when the file is missing or `UPDATE_GOLDEN=1`, writes it and passes (first run creates; commit the files).

- [ ] **Step 1: Write failing tests `tests/Unit/Lhdn/UblDocumentBuilderTest.php`**

```php
<?php

use App\Actions\Documents\CreateDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Enums\Environment;
use App\Lhdn\Ubl\UblDocumentBuilder;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-08-20 03:04:05');
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create([
        'name' => 'Vendor One Sdn Bhd', 'tin' => 'C12345678901', 'id_number' => '202001012345', 'sst_number' => 'W10-1808-32000001',
        'msic_code' => '47911', 'business_activity_description' => 'Retail sale via internet', 'address_line1' => '1 Jalan Test',
        'postcode' => '50000', 'city' => 'Kuala Lumpur', 'state_code' => '14', 'email' => 'vendor@example.com', 'phone' => '+60123456789',
    ]);
});
afterEach(fn () => Carbon::setTestNow());

function ublDoc(Issuer $issuer, array $overrides = []): \App\Models\Document
{
    $payload = array_replace_recursive([
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true], 'issue_date' => '2026-08-20', 'submit' => false,
        'lines' => [
            ['classification_code' => '022', 'description' => 'Widget', 'quantity' => 2, 'unit_code' => 'C62', 'unit_price' => '10.50', 'tax_type' => '02', 'tax_rate' => 6],
            ['classification_code' => '022', 'description' => 'Exempt thing', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => '5', 'tax_type' => 'E', 'tax_exemption_reason' => 'Exempt goods', 'discount_amount' => '1.00'],
        ],
        'source' => ['system' => 'test', 'ref' => 'ref-'.bin2hex(random_bytes(3))],
    ], $overrides);
    $doc = app(CreateDocument::class)->handle(CreateDocumentData::from($payload))->document;
    $doc->forceFill(['lhdn_internal_id' => 'INV-0001'])->save();

    return $doc->refresh()->load('lines', 'issuer');
}

it('builds a v1.1 invoice with supplier, general-public buyer, lines, tax and monetary totals', function () {
    $ubl = (new UblDocumentBuilder)->build(ublDoc($this->issuer));
    $inv = $ubl['Invoice'][0];
    expect($ubl['_D'])->toBe('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2')
        ->and($inv['ID'][0]['_'])->toBe('INV-0001')
        ->and($inv['IssueDate'][0]['_'])->toBe('2026-08-20')
        ->and($inv['IssueTime'][0]['_'])->toBe('03:04:05Z')
        ->and($inv['InvoiceTypeCode'][0])->toBe(['_' => '01', 'listVersionID' => '1.1'])
        ->and($inv['DocumentCurrencyCode'][0]['_'])->toBe('MYR')
        ->and($inv)->not->toHaveKey('TaxExchangeRate')
        ->and($inv['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0])->toBe(['_' => 'C12345678901', 'schemeID' => 'TIN'])
        ->and($inv['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'][1]['ID'][0])->toBe(['_' => '202001012345', 'schemeID' => 'BRN'])
        ->and($inv['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'][2]['ID'][0])->toBe(['_' => 'W10-1808-32000001', 'schemeID' => 'SST'])
        ->and($inv['AccountingCustomerParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0]['_'])->toBe('EI00000000010')
        ->and($inv['InvoiceLine'])->toHaveCount(2)
        ->and($inv['InvoiceLine'][0]['InvoicedQuantity'][0])->toBe(['_' => 2.0, 'unitCode' => 'C62'])
        ->and($inv['InvoiceLine'][0]['LineExtensionAmount'][0])->toBe(['_' => 21.0, 'currencyID' => 'MYR'])
        ->and($inv['InvoiceLine'][0]['TaxTotal'][0]['TaxSubtotal'][0]['TaxCategory'][0]['Percent'][0]['_'])->toBe(6.0)
        ->and($inv['InvoiceLine'][1]['AllowanceCharge'][0]['Amount'][0]['_'])->toBe(1.0)
        ->and($inv['InvoiceLine'][1]['TaxTotal'][0]['TaxSubtotal'][0]['TaxCategory'][0]['TaxExemptionReason'][0]['_'])->toBe('Exempt goods')
        ->and($inv['TaxTotal'][0]['TaxAmount'][0]['_'])->toBe(1.26)
        ->and($inv['LegalMonetaryTotal'][0]['PayableAmount'][0])->toBe(['_' => 26.26, 'currencyID' => 'MYR'])
        ->and($ubl)->not->toHaveKey('UBLExtensions');
    assertMatchesGolden('invoice-myr', $ubl);
});

it('adds BillingReference for notes and TaxExchangeRate for foreign currency; self-billed type code', function () {
    $orig = ublDoc($this->issuer);
    $orig->forceFill(['lhdn_uuid' => 'UUID-ORIG'])->save();
    $note = ublDoc($this->issuer, ['type' => 'credit_note', 'original_document_ref' => ['document_id' => $orig->id]]);
    $ublNote = (new UblDocumentBuilder)->build($note);
    expect($ublNote['Invoice'][0]['InvoiceTypeCode'][0]['_'])->toBe('02')
        ->and($ublNote['Invoice'][0]['BillingReference'][0]['InvoiceDocumentReference'][0]['ID'][0]['_'])->toBe('INV-0001')
        ->and($ublNote['Invoice'][0]['BillingReference'][0]['InvoiceDocumentReference'][0]['UUID'][0]['_'])->toBe('UUID-ORIG');
    assertMatchesGolden('credit-note', $ublNote);

    $usd = ublDoc($this->issuer, ['type' => 'self_billed_invoice', 'currency' => 'USD', 'exchange_rate' => '4.75', 'buyer' => ['general_public' => false, 'name' => 'Acme Inc', 'tin' => 'EI00000000020', 'id_type' => 'BRN', 'id_number' => 'NA', 'country_code' => 'USA']]);
    $ublUsd = (new UblDocumentBuilder)->build($usd);
    expect($ublUsd['Invoice'][0]['InvoiceTypeCode'][0]['_'])->toBe('11')
        ->and($ublUsd['Invoice'][0]['TaxExchangeRate'][0]['CalculationRate'][0]['_'])->toBe(4.75)
        ->and($ublUsd['Invoice'][0]['TaxCurrencyCode'][0]['_'])->toBe('MYR')
        ->and($ublUsd['Invoice'][0]['InvoiceLine'][0]['LineExtensionAmount'][0]['currencyID'])->toBe('USD');
    assertMatchesGolden('self-billed-usd', $ublUsd);
});

it('is deterministic', function () {
    $doc = ublDoc($this->issuer);
    expect((new UblDocumentBuilder)->build($doc))->toBe((new UblDocumentBuilder)->build($doc->fresh()->load('lines', 'issuer')));
});
```

`tests/Support/Golden.php`
```php
<?php

function assertMatchesGolden(string $name, array $actual): void
{
    $path = base_path("tests/Fixtures/ubl/{$name}.json");
    $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    if (! is_file($path) || getenv('UPDATE_GOLDEN') === '1') {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $json);
        expect(true)->toBeTrue();

        return;
    }
    expect($json)->toBe((string) file_get_contents($path), "Golden file {$name}.json differs; run with UPDATE_GOLDEN=1 to regenerate after reviewing the change.");
}
```
Load it from `tests/Pest.php`: `require_once __DIR__.'/Support/Golden.php';` (add after the OPENSSL block). Note the golden files must be committed after the first green run; the `source.ref` random part does not appear in UBL (only `lhdn_internal_id`, fixed to `INV-0001`), and the test-now freeze keeps timestamps stable; ULIDs do not appear in the UBL output.

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Lhdn/UblDocumentBuilderTest.php` → FAIL.

- [ ] **Step 3: Implement**

`app/Lhdn/Ubl/UblParty.php`
```php
<?php

namespace App\Lhdn\Ubl;

use App\Models\Issuer;

final class UblParty
{
    /** @return array<string, mixed> */
    public static function supplier(Issuer $issuer): array
    {
        $ids = [
            ['ID' => [['_' => $issuer->tin, 'schemeID' => 'TIN']]],
            ['ID' => [['_' => $issuer->id_number, 'schemeID' => $issuer->id_type->value]]],
        ];
        if ($issuer->sst_number !== null && $issuer->sst_number !== '') {
            $ids[] = ['ID' => [['_' => $issuer->sst_number, 'schemeID' => 'SST']]];
        }
        if ($issuer->tourism_tax_number !== null && $issuer->tourism_tax_number !== '') {
            $ids[] = ['ID' => [['_' => $issuer->tourism_tax_number, 'schemeID' => 'TTX']]];
        }

        return ['Party' => [[
            'IndustryClassificationCode' => [['_' => $issuer->msic_code, 'name' => $issuer->business_activity_description]],
            'PartyIdentification' => $ids,
            'PostalAddress' => [self::address($issuer->city, $issuer->postcode, $issuer->state_code, array_values(array_filter([$issuer->address_line1, $issuer->address_line2, $issuer->address_line3], fn ($l) => $l !== null && $l !== '')), $issuer->country_code)],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => $issuer->name]]]],
            'Contact' => [['Telephone' => [['_' => $issuer->phone]], 'ElectronicMail' => [['_' => $issuer->email]]]],
        ]]];
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public static function buyer(array $snapshot): array
    {
        $general = (bool) ($snapshot['general_public'] ?? false);
        $tin = (string) ($snapshot['tin'] ?? ($general ? 'EI00000000010' : 'NA'));
        $idType = (string) ($snapshot['id_type'] ?? 'BRN');
        $idNumber = (string) ($snapshot['id_number'] ?? 'NA');
        $ids = [
            ['ID' => [['_' => $tin, 'schemeID' => 'TIN']]],
            ['ID' => [['_' => $idNumber, 'schemeID' => $idType]]],
        ];
        if (! empty($snapshot['sst_number'])) {
            $ids[] = ['ID' => [['_' => (string) $snapshot['sst_number'], 'schemeID' => 'SST']]];
        }
        $lines = array_values(array_filter([$snapshot['address_line1'] ?? null, $snapshot['address_line2'] ?? null, $snapshot['address_line3'] ?? null], fn ($l) => $l !== null && $l !== ''));
        $contact = ['Telephone' => [['_' => (string) ($snapshot['phone'] ?? 'NA')]]];
        if (! empty($snapshot['email'])) {
            $contact['ElectronicMail'] = [['_' => (string) $snapshot['email']]];
        }

        return ['Party' => [[
            'PartyIdentification' => $ids,
            'PostalAddress' => [self::address((string) ($snapshot['city'] ?? 'NA'), (string) ($snapshot['postcode'] ?? 'NA'), (string) ($snapshot['state_code'] ?? '17'), $lines === [] ? ['NA'] : $lines, (string) ($snapshot['country_code'] ?? 'MYS'))],
            'PartyLegalEntity' => [['RegistrationName' => [['_' => (string) ($snapshot['name'] ?? 'General Public')]]]],
            'Contact' => [$contact],
        ]]];
    }

    /** @param list<string> $lines @return array<string, mixed> */
    private static function address(string $city, string $postcode, string $state, array $lines, string $country): array
    {
        return [
            'CityName' => [['_' => $city]],
            'PostalZone' => [['_' => $postcode]],
            'CountrySubentityCode' => [['_' => $state]],
            'AddressLine' => array_map(fn (string $l) => ['Line' => [['_' => $l]]], $lines),
            'Country' => [['IdentificationCode' => [['_' => $country, 'listID' => 'ISO3166-1', 'listAgencyID' => '6']]]],
        ];
    }
}
```

`app/Lhdn/Ubl/UblDocumentBuilder.php`
```php
<?php

namespace App\Lhdn\Ubl;

use App\Domain\Documents\Money;
use App\Models\Document;
use App\Models\DocumentLine;
use Brick\Math\BigDecimal;

class UblDocumentBuilder
{
    public const TAX_SCHEME = ['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]];

    /** @return array<string, mixed> */
    public function build(Document $document): array
    {
        $document->loadMissing('lines', 'issuer');
        $cur = $document->currency;
        $inv = [
            'ID' => [['_' => $document->lhdn_internal_id ?? $document->id]],
            'IssueDate' => [['_' => $document->issue_date->toDateString()]],
            'IssueTime' => [['_' => $document->created_at->copy()->utc()->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => $document->type->lhdnCode(), 'listVersionID' => '1.1']],
            'DocumentCurrencyCode' => [['_' => $cur]],
            'TaxCurrencyCode' => [['_' => 'MYR']],
        ];
        if ($cur !== 'MYR' && $document->exchange_rate !== null) {
            $inv['TaxExchangeRate'] = [[
                'SourceCurrencyCode' => [['_' => $cur]],
                'TargetCurrencyCode' => [['_' => 'MYR']],
                'CalculationRate' => [['_' => self::num($document->exchange_rate)]],
            ]];
        }
        if ($document->type->requiresOriginalRef()) {
            $original = $document->originalDocument;
            $ref = ['ID' => [['_' => $original?->lhdn_internal_id ?? $original?->id ?? $document->original_lhdn_uuid ?? '']]];
            $uuid = $original?->lhdn_uuid ?? $document->original_lhdn_uuid;
            if ($uuid !== null) {
                $ref['UUID'] = [['_' => $uuid]];
            }
            $inv['BillingReference'] = [['InvoiceDocumentReference' => [$ref]]];
        }
        $inv['AccountingSupplierParty'] = [UblParty::supplier($document->issuer)];
        $inv['AccountingCustomerParty'] = [UblParty::buyer($document->buyer_snapshot)];

        $subtotals = [];
        $inv['InvoiceLine'] = $document->lines->map(function (DocumentLine $line) use ($cur, &$subtotals): array {
            $key = $line->tax_type.'|'.($line->tax_rate ?? '');
            $subtotals[$key] ??= ['tax_type' => $line->tax_type, 'rate' => $line->tax_rate, 'taxable' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'exemption' => $line->tax_exemption_reason];
            $subtotals[$key]['taxable'] = $subtotals[$key]['taxable']->plus(Money::of($line->subtotal));
            $subtotals[$key]['tax'] = $subtotals[$key]['tax']->plus(Money::of($line->tax_amount));

            return $this->line($line, $cur);
        })->values()->all();

        $inv['TaxTotal'] = [[
            'TaxAmount' => [['_' => self::num($document->tax_total), 'currencyID' => $cur]],
            'TaxSubtotal' => array_values(array_map(fn (array $s) => [
                'TaxableAmount' => [['_' => self::num(Money::str($s['taxable'])), 'currencyID' => $cur]],
                'TaxAmount' => [['_' => self::num(Money::str($s['tax'])), 'currencyID' => $cur]],
                'TaxCategory' => [$this->taxCategory($s['tax_type'], $s['rate'], $s['exemption'])],
            ], $subtotals)),
        ]];
        $inv['LegalMonetaryTotal'] = [[
            'LineExtensionAmount' => [['_' => self::num($document->total_excluding_tax), 'currencyID' => $cur]],
            'TaxExclusiveAmount' => [['_' => self::num($document->total_excluding_tax), 'currencyID' => $cur]],
            'TaxInclusiveAmount' => [['_' => self::num($document->total_including_tax), 'currencyID' => $cur]],
            'AllowanceTotalAmount' => [['_' => self::num($document->discount_total), 'currencyID' => $cur]],
            'PayableAmount' => [['_' => self::num($document->total_payable), 'currencyID' => $cur]],
        ]];

        return [
            '_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            '_A' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            '_B' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'Invoice' => [$inv],
        ];
    }

    /** @return array<string, mixed> */
    private function line(DocumentLine $line, string $cur): array
    {
        $gross = Money::round2(Money::of($line->quantity)->multipliedBy(Money::of($line->unit_price)));
        $out = [
            'ID' => [['_' => (string) $line->position]],
            'InvoicedQuantity' => [['_' => self::num($line->quantity), 'unitCode' => $line->unit_code]],
            'LineExtensionAmount' => [['_' => self::num($line->subtotal), 'currencyID' => $cur]],
        ];
        if (Money::of($line->discount_amount)->isPositive()) {
            $out['AllowanceCharge'] = [[
                'ChargeIndicator' => [['_' => false]],
                'AllowanceChargeReason' => [['_' => 'Discount']],
                'Amount' => [['_' => self::num($line->discount_amount), 'currencyID' => $cur]],
            ]];
        }
        $out['TaxTotal'] = [[
            'TaxAmount' => [['_' => self::num($line->tax_amount), 'currencyID' => $cur]],
            'TaxSubtotal' => [[
                'TaxableAmount' => [['_' => self::num($line->subtotal), 'currencyID' => $cur]],
                'TaxAmount' => [['_' => self::num($line->tax_amount), 'currencyID' => $cur]],
                'TaxCategory' => [$this->taxCategory($line->tax_type, $line->tax_rate, $line->tax_exemption_reason)],
            ]],
        ]];
        $out['Item'] = [[
            'CommodityClassification' => [['ItemClassificationCode' => [['_' => $line->classification_code, 'listID' => 'CLASS']]]],
            'Description' => [['_' => $line->description]],
        ]];
        $out['Price'] = [['PriceAmount' => [['_' => self::num($line->unit_price), 'currencyID' => $cur]]]];
        $out['ItemPriceExtension'] = [['Amount' => [['_' => self::num(Money::str($gross)), 'currencyID' => $cur]]]];

        return $out;
    }

    /** @return array<string, mixed> */
    private function taxCategory(string $taxType, ?string $rate, ?string $exemption): array
    {
        $cat = ['ID' => [['_' => $taxType]]];
        if ($rate !== null && $taxType !== 'E' && $taxType !== '06') {
            $cat['Percent'] = [['_' => self::num($rate)]];
        }
        if ($taxType === 'E' && $exemption !== null) {
            $cat['TaxExemptionReason'] = [['_' => $exemption]];
        }
        $cat['TaxScheme'] = [self::TAX_SCHEME];

        return $cat;
    }

    private static function num(string $decimal): float
    {
        return (float) $decimal;
    }
}
```
(`LineExtensionAmount` at document level = `total_excluding_tax` is the sum of line subtotals after discount — consistent with LHDN's definition.)

- [ ] **Step 4: Run tests** — first run creates the three golden files; re-run to confirm they compare equal; inspect the JSON once by eye (supplier/buyer/totals) and commit the fixtures. `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Lhdn/Ubl tests/Unit/Lhdn/UblDocumentBuilderTest.php tests/Support/Golden.php tests/Fixtures/ubl tests/Pest.php
git commit -m "feat(lhdn): UBL 2.1 JSON document builder with golden-file tests"
```

---

### Task 5: `DocumentSigner` (XAdES-style JSON signature) + `SignedDocument`

**Files:**
- Create: `app/Lhdn/Signing/DocumentSigner.php`, `app/Lhdn/Signing/SignedDocument.php`, `app/Lhdn/Signing/SigningMaterial.php`, `tests/Unit/Lhdn/DocumentSignerTest.php`

**Interfaces:**
- Consumes: cert fixtures `tests/Fixtures/certs/test-cert.pem`, `test-key.pem` (Plan 1); `IssuerSecret` decrypted `signing_certificate`/`signing_key` (pass as strings).
- Produces:
  - `SigningMaterial(string $certPem, string $keyPem)` + `static fromSecret(IssuerSecret $secret): self` (throws `LhdnException::auth('Issuer has no signing certificate')` when absent).
  - `SignedDocument(array $document, string $json, string $hashHex)` — `json` = `json_encode($document, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)` (minified), `hashHex = sha256(json)`.
  - `DocumentSigner::sign(array $ubl, SigningMaterial $material, ?CarbonImmutable $signingTime = null): SignedDocument` — procedure (LHDN SDK JSON signing):
    1. `$doc = $ubl` without `Invoice[0].UBLExtensions` and `Invoice[0].Signature`; `$docJson` = minified JSON (same flags); `DocDigest` = base64(sha256 raw of `$docJson`); `Sig` = base64(openssl_sign($docJson, key, OPENSSL_ALGO_SHA256)).
    2. Cert: DER = base64-decode of PEM body; `CertDigest` = base64(sha256 raw of DER); `X509Certificate` = base64(DER) (single line); `X509IssuerName` = issuer DN string from `openssl_x509_parse` (`CN=…, O=…, C=…` joined with `, ` in reverse-RDN order as OpenSSL's `name` gives `/C=MY/O=…/CN=…` → produce `CN=Test Issuer, O=Billplz Test Issuer, C=MY`); `X509SerialNumber` = decimal serial (`serialNumber` from `openssl_x509_parse`); `X509SubjectName` = subject DN in the same style.
    3. `SigningTime` = `$signingTime ?? now()` UTC `Y-m-d\TH:i:s\Z`.
    4. `SignedProperties` = `[{Id: 'id-xades-signed-props', SignedSignatureProperties: [{SigningTime: [{_: time}], SigningCertificate: [{Cert: [{CertDigest: [{DigestMethod: [{_: '', Algorithm: 'http://www.w3.org/2001/04/xmlenc#sha256'}], DigestValue: [{_: CertDigest}]}], IssuerSerial: [{X509IssuerName: [{_: issuerName}], X509SerialNumber: [{_: serial}]}]}]}]}]}]`; `QualifyingProperties = [{Target: 'signature', SignedProperties: …}]`; `PropsDigest` = base64(sha256 raw of minified JSON of `{"Target":"signature","SignedProperties":[…]}`).
    5. `Signature` object: `{Id: 'signature', Object: [{QualifyingProperties: [...]}], KeyInfo: [{X509Data: [{X509Certificate: [{_: cert}], X509SubjectName: [{_: subject}], X509IssuerSerial: [{X509IssuerName: [{_: issuer}], X509SerialNumber: [{_: serial}]}]}]}], SignatureValue: [{_: Sig}], SignedInfo: [{SignatureMethod: [{_: '', Algorithm: 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'}], Reference: [{Type: 'http://uri.etsi.org/01903/v1.3.2#SignedProperties', URI: '#id-xades-signed-props', DigestMethod: [{_: '', Algorithm: 'http://www.w3.org/2001/04/xmlenc#sha256'}], DigestValue: [{_: PropsDigest}]}, {Type: '', URI: '', DigestMethod: [{_: '', Algorithm: 'http://www.w3.org/2001/04/xmlenc#sha256'}], DigestValue: [{_: DocDigest}]}]}]}`.
    6. Output document = `$ubl` with `Invoice[0].UBLExtensions = [{UBLExtension: [{ExtensionURI: [{_: 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'}], ExtensionContent: [{UBLDocumentSignatures: [{SignatureInformation: [{ID: [{_: 'urn:oasis:names:specification:ubl:signature:1'}], ReferencedSignatureID: [{_: 'urn:oasis:names:specification:ubl:signature:Invoice'}], Signature: signatureObject}]}]}]}]}]` placed FIRST in the `Invoice[0]` object, and `Invoice[0].Signature = [{ID: [{_: 'urn:oasis:names:specification:ubl:signature:Invoice'}], SignatureMethod: [{_: 'urn:oasis:names:specification:ubl:dsig:enveloped:xades'}]}]` placed after `TaxCurrencyCode`/`TaxExchangeRate` — ordering of keys inside JSON objects does not affect LHDN validation, so keep: `UBLExtensions` first, then the original keys, then `Signature` appended last.
  - `DocumentSigner::verify(array $signedDocument, string $certPem): bool` — recomputes `DocDigest`/`PropsDigest` and `openssl_verify`s `SignatureValue` (used by tests and Plan 4 audits).

- [ ] **Step 1: Write failing tests `tests/Unit/Lhdn/DocumentSignerTest.php`**

```php
<?php

use App\Lhdn\Signing\DocumentSigner;
use App\Lhdn\Signing\SigningMaterial;
use Carbon\CarbonImmutable;

$fx = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

it('signs a UBL document and the signature verifies against the certificate', function () use ($fx) {
    $ubl = ['_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', '_A' => 'a', '_B' => 'b', 'Invoice' => [['ID' => [['_' => 'INV-1']], 'IssueDate' => [['_' => '2026-08-20']]]]];
    $signer = new DocumentSigner;
    $signed = $signer->sign($ubl, new SigningMaterial($fx('test-cert.pem'), $fx('test-key.pem')), CarbonImmutable::parse('2026-08-20T03:04:05Z'));
    $inv = $signed->document['Invoice'][0];
    expect(array_key_first($inv))->toBe('UBLExtensions')
        ->and($inv['Signature'][0]['ID'][0]['_'])->toBe('urn:oasis:names:specification:ubl:signature:Invoice')
        ->and($signed->hashHex)->toBe(hash('sha256', $signed->json))
        ->and(strlen($signed->json))->toBeGreaterThan(100);
    $sig = $inv['UBLExtensions'][0]['UBLExtension'][0]['ExtensionContent'][0]['UBLDocumentSignatures'][0]['SignatureInformation'][0]['Signature'];
    expect($sig['SignedInfo'][0]['Reference'][1]['DigestValue'][0]['_'])
        ->toBe(base64_encode(hash('sha256', json_encode($ubl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true)));
    expect($sig['Object'][0]['QualifyingProperties'][0]['SignedProperties'][0]['SignedSignatureProperties'][0]['SigningTime'][0]['_'])->toBe('2026-08-20T03:04:05Z');
    $certDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $fx('test-cert.pem')) ?? '', true);
    expect($sig['KeyInfo'][0]['X509Data'][0]['X509Certificate'][0]['_'])->toBe(base64_encode((string) $certDer));
    expect($signer->verify($signed->document, $fx('test-cert.pem')))->toBeTrue();
});

it('detects tampering and rejects mismatched material', function () use ($fx) {
    $ubl = ['_D' => 'x', '_A' => 'a', '_B' => 'b', 'Invoice' => [['ID' => [['_' => 'INV-1']]]]];
    $signer = new DocumentSigner;
    $signed = $signer->sign($ubl, new SigningMaterial($fx('test-cert.pem'), $fx('test-key.pem')));
    $tampered = $signed->document;
    $tampered['Invoice'][0]['ID'][0]['_'] = 'INV-2';
    expect($signer->verify($tampered, $fx('test-cert.pem')))->toBeFalse();
    expect(fn () => $signer->sign($ubl, new SigningMaterial($fx('test-cert.pem'), $fx('other-key.pem'))))->toThrow(\App\Lhdn\LhdnException::class);
});
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement**

`app/Lhdn/Signing/SigningMaterial.php`
```php
<?php

namespace App\Lhdn\Signing;

use App\Lhdn\LhdnException;
use App\Models\IssuerSecret;

final class SigningMaterial
{
    public function __construct(public readonly string $certPem, public readonly string $keyPem) {}

    public static function fromSecret(?IssuerSecret $secret): self
    {
        if ($secret === null || ! $secret->hasCertificate()) {
            throw LhdnException::auth('Issuer has no signing certificate.');
        }

        return new self((string) $secret->signing_certificate, (string) $secret->signing_key);
    }
}
```

`app/Lhdn/Signing/SignedDocument.php`
```php
<?php

namespace App\Lhdn\Signing;

final class SignedDocument
{
    /** @param array<string, mixed> $document */
    public function __construct(public readonly array $document, public readonly string $json, public readonly string $hashHex) {}
}
```

`app/Lhdn/Signing/DocumentSigner.php`
```php
<?php

namespace App\Lhdn\Signing;

use App\Lhdn\LhdnException;
use Carbon\CarbonImmutable;

class DocumentSigner
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private const SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /** @param array<string, mixed> $ubl */
    public function sign(array $ubl, SigningMaterial $material, ?CarbonImmutable $signingTime = null): SignedDocument
    {
        $cert = @openssl_x509_read($material->certPem);
        $key = @openssl_pkey_get_private($material->keyPem);
        if ($cert === false || $key === false) {
            throw LhdnException::auth('Signing material could not be read.');
        }
        if (! openssl_x509_check_private_key($cert, $key)) {
            throw LhdnException::auth('Signing key does not match the certificate.');
        }

        $invoice = $ubl['Invoice'][0];
        unset($invoice['UBLExtensions'], $invoice['Signature']);
        $bare = $ubl;
        $bare['Invoice'] = [$invoice];
        $docJson = self::encode($bare);
        $docDigest = base64_encode(hash('sha256', $docJson, true));
        $signature = '';
        if (! openssl_sign($docJson, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw LhdnException::auth('Signing failed: '.(openssl_error_string() ?: 'unknown error'));
        }
        $sig = base64_encode($signature);

        $der = self::der($material->certPem);
        $certDigest = base64_encode(hash('sha256', $der, true));
        $parsed = openssl_x509_parse($cert) ?: [];
        $issuerName = self::dn((array) ($parsed['issuer'] ?? []));
        $subjectName = self::dn((array) ($parsed['subject'] ?? []));
        $serial = (string) ($parsed['serialNumber'] ?? '');
        $time = ($signingTime ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s\Z');

        $signedProps = [[
            'Id' => 'id-xades-signed-props',
            'SignedSignatureProperties' => [[
                'SigningTime' => [['_' => $time]],
                'SigningCertificate' => [['Cert' => [[
                    'CertDigest' => [['DigestMethod' => [['_' => '', 'Algorithm' => self::SHA256]], 'DigestValue' => [['_' => $certDigest]]]],
                    'IssuerSerial' => [['X509IssuerName' => [['_' => $issuerName]], 'X509SerialNumber' => [['_' => $serial]]]],
                ]]]],
            ]],
        ]];
        $qualifying = [['Target' => 'signature', 'SignedProperties' => $signedProps]];
        $propsDigest = base64_encode(hash('sha256', self::encode($qualifying[0]), true));

        $signatureObject = [
            'Id' => 'signature',
            'Object' => [['QualifyingProperties' => $qualifying]],
            'KeyInfo' => [['X509Data' => [[
                'X509Certificate' => [['_' => base64_encode($der)]],
                'X509SubjectName' => [['_' => $subjectName]],
                'X509IssuerSerial' => [['X509IssuerName' => [['_' => $issuerName]], 'X509SerialNumber' => [['_' => $serial]]]],
            ]]]],
            'SignatureValue' => [['_' => $sig]],
            'SignedInfo' => [[
                'SignatureMethod' => [['_' => '', 'Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']],
                'Reference' => [
                    ['Type' => 'http://uri.etsi.org/01903/v1.3.2#SignedProperties', 'URI' => '#id-xades-signed-props', 'DigestMethod' => [['_' => '', 'Algorithm' => self::SHA256]], 'DigestValue' => [['_' => $propsDigest]]],
                    ['Type' => '', 'URI' => '', 'DigestMethod' => [['_' => '', 'Algorithm' => self::SHA256]], 'DigestValue' => [['_' => $docDigest]]],
                ],
            ]],
        ];
        $extensions = [['UBLExtension' => [['ExtensionURI' => [['_' => 'urn:oasis:names:specification:ubl:dsig:enveloped:xades']], 'ExtensionContent' => [['UBLDocumentSignatures' => [['SignatureInformation' => [[
            'ID' => [['_' => 'urn:oasis:names:specification:ubl:signature:1']],
            'ReferencedSignatureID' => [['_' => 'urn:oasis:names:specification:ubl:signature:Invoice']],
            'Signature' => $signatureObject,
        ]]]]]]]]]];

        $signedInvoice = ['UBLExtensions' => $extensions] + $invoice;
        $signedInvoice['Signature'] = [['ID' => [['_' => 'urn:oasis:names:specification:ubl:signature:Invoice']], 'SignatureMethod' => [['_' => 'urn:oasis:names:specification:ubl:dsig:enveloped:xades']]]];
        $out = $ubl;
        $out['Invoice'] = [$signedInvoice];
        $json = self::encode($out);

        return new SignedDocument($out, $json, hash('sha256', $json));
    }

    /** @param array<string, mixed> $signedDocument */
    public function verify(array $signedDocument, string $certPem): bool
    {
        $invoice = $signedDocument['Invoice'][0] ?? null;
        if (! is_array($invoice)) {
            return false;
        }
        $sig = $invoice['UBLExtensions'][0]['UBLExtension'][0]['ExtensionContent'][0]['UBLDocumentSignatures'][0]['SignatureInformation'][0]['Signature'] ?? null;
        if (! is_array($sig)) {
            return false;
        }
        unset($invoice['UBLExtensions'], $invoice['Signature']);
        $bare = $signedDocument;
        $bare['Invoice'] = [$invoice];
        $docJson = self::encode($bare);
        $expectedDigest = base64_encode(hash('sha256', $docJson, true));
        if (($sig['SignedInfo'][0]['Reference'][1]['DigestValue'][0]['_'] ?? null) !== $expectedDigest) {
            return false;
        }
        $props = $sig['Object'][0]['QualifyingProperties'][0] ?? null;
        if ($props === null || ($sig['SignedInfo'][0]['Reference'][0]['DigestValue'][0]['_'] ?? null) !== base64_encode(hash('sha256', self::encode($props), true))) {
            return false;
        }
        $pub = openssl_pkey_get_public($certPem);
        if ($pub === false) {
            return false;
        }
        $signature = base64_decode((string) ($sig['SignatureValue'][0]['_'] ?? ''), true);

        return $signature !== false && openssl_verify($docJson, $signature, $pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @param array<string, mixed> $value */
    public static function encode(array $value): string
    {
        return (string) json_encode($value, self::JSON_FLAGS);
    }

    private static function der(string $pem): string
    {
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($body, true);
        if ($der === false) {
            throw LhdnException::auth('Certificate PEM could not be decoded.');
        }

        return $der;
    }

    /** @param array<string, string|list<string>> $parts */
    private static function dn(array $parts): string
    {
        // OpenSSL gives ['C' => 'MY', 'O' => '…', 'CN' => '…'] in cert order; XAdES/RFC 2253 string lists CN first.
        $order = ['CN', 'OU', 'O', 'L', 'ST', 'C', 'emailAddress'];
        $out = [];
        foreach ($order as $k) {
            if (isset($parts[$k])) {
                foreach ((array) $parts[$k] as $v) {
                    $out[] = "{$k}={$v}";
                }
            }
        }
        foreach ($parts as $k => $v) {
            if (! in_array($k, $order, true)) {
                foreach ((array) $v as $vv) {
                    $out[] = "{$k}={$vv}";
                }
            }
        }

        return implode(', ', $out);
    }
}
```

- [ ] **Step 4: Run tests** — pass (on Windows `openssl_sign` works without config). `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Lhdn/Signing tests/Unit/Lhdn/DocumentSignerTest.php
git commit -m "feat(lhdn): XAdES-style JSON document signer with verification"
```

---
### Task 6: Issuer onboarding (`verify-tin`, `authorize`) and `POST /tin/validate`

**Files:**
- Create: `app/Actions/Issuers/VerifyIssuerTin.php`, `app/Actions/Issuers/AuthorizeIssuer.php`, `app/Actions/Tin/ValidateTin.php`, `app/Data/Requests/Tin/ValidateTinData.php`, `app/Data/Resources/TinValidationData.php`, `app/Http/Controllers/Api/V1/IssuerOnboardingController.php`, `app/Http/Controllers/Api/V1/TinController.php`, `tests/Feature/Lhdn/IssuerOnboardingTest.php`, `tests/Feature/Lhdn/TinValidateTest.php`
- Modify: `routes/api.php`, `app/Http/Problem/ProblemResponse.php` (map `LhdnException`), `tests/Feature/TenantIsolationSweepTest.php` (rows for verify-tin/authorize)

**Interfaces:**
- Consumes: `LhdnClientFactory`, `LhdnException`, `IssuerStatus`, `IssuerActivator`, `AuditLogger`, `Issuer` (`tin_verified_at`, `authorized_at`), `IssuerSecret::credentials_verified_at`.
- Produces:
  - `ProblemResponse` mapping (add before the generic `HttpExceptionInterface` branch): `LhdnException` → Transient/Breaker `[503, 'Service Unavailable', $e->getMessage(), 'lhdn_unavailable', []]`, Auth `[409, 'Conflict', $e->getMessage(), 'lhdn_credentials_invalid', []]`, Terminal `[422, 'Unprocessable Entity', $e->getMessage(), 'lhdn_rejected', []]`. Detail must not include secrets (the exception messages never do).
  - `VerifyIssuerTin::handle(Issuer $issuer): Issuer` — client: `lhdn_mode === OwnCredentials` → `factory->for($issuer)` (credentials required, else the resolver throws Auth → 409 `lhdn_credentials_invalid`); intermediary → `factory->forEnvironment($issuer->environment)`; calls `validateTin($issuer->environment, $issuer->tin, $issuer->id_type->value, $issuer->id_number, $issuer)`; `true` → `tin_verified_at = now()`, status `draft → tin_verified` (leave `authorized/active` statuses untouched — re-verification only refreshes `tin_verified_at`); `false` → `ProblemException(422, 'Unprocessable Entity', 'LHDN does not recognise this TIN / ID combination.', 'tin_invalid')`. Audit `issuer.tin_verified` (`{tin_verified: bool}`).
  - `AuthorizeIssuer::handle(Issuer $issuer): Issuer` — requires `tin_verified_at` (else 409 `tin_not_verified`); `factory->for($issuer)->token($issuer)` (Auth failure → 409 `lhdn_credentials_invalid` via the mapping; for intermediary mode the detail additionally says "Ask the merchant to grant Billplz intermediary access in MyInvois"); success → `authorized_at = now()`, status `tin_verified → authorized` (if already authorized/active keep), `secret->credentials_verified_at = now()` when a secret exists, then `IssuerActivator::apply()` (cert present → active). Audit `issuer.authorized` (`{lhdn_mode}`).
  - `ValidateTin::handle(ValidateTinData $data): TinValidationData` — cache key `tin:{tenant}:{env}:{sha1(tin|idType|idValue)}` for `config('lhdn.tin_cache_hours')`; client: `issuer_id` given → that issuer (env-scoped, 404 `issuer_not_found`) via `factory->for()`; else the tenant's first `active` issuer in the current environment (for attempt recording + mode) via `factory->for()`; none → intermediary `factory->forEnvironment()` with `issuer = null`?? `HttpLhdnClient::validateTin` requires an issuer for recording → ruling: when no issuer exists, use the intermediary client with the first issuer of the tenant in that environment regardless of status; if the tenant has NO issuer at all → 409 `issuer_required` ("Create an issuer first"). Result DTO `TinValidationData(string $tin, string $id_type, string $id_number, bool $valid, string $checked_at, bool $cached)`.
  - `ValidateTinData(string $tin /*max 20*/, IdType $id_type, string $id_number /*max 30*/, ?string $issuer_id = null)`.
  - Routes: `POST /issuers/{issuer}/verify-tin`, `POST /issuers/{issuer}/authorize` (`ability:issuers:manage`; return `IssuerData` wrapped, 200 via `response()->json`), `POST /tin/validate` (`ability:read`, returns `TinValidationData->wrap('data')`, 200 — not 201: return `response()->json(['data' => …->toArray()], 200)`).

- [ ] **Step 1: Write failing tests**

`tests/Feature/Lhdn/IssuerOnboardingTest.php`
```php
<?php

use App\Enums\Environment;
use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Lhdn\LhdnException;
use App\Models\AuditLog;
use App\Models\Issuer;
use App\Models\Tenant;

$certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->issuer = Issuer::factory()->for($this->tenant)->create(['environment' => Environment::Sandbox]); // draft, intermediary
    $this->h = serviceHeaders($this->tenant, 'sandbox');
});

it('verifies the issuer TIN via LHDN and moves draft → tin_verified', function () {
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")
        ->assertOk()->assertJsonPath('data.status', 'tin_verified')->assertJsonPath('data.tin_verified_at', fn ($v) => $v !== null);
    expect(collect(fakeLhdn()->calls())->last()['operation'])->toBe('validate_tin')
        ->and(AuditLog::where('action', 'issuer.tin_verified')->count())->toBe(1);
});

it('returns 422 tin_invalid when LHDN does not know the TIN', function () {
    fakeLhdn()->invalidTin($this->issuer->tin);
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(422)->assertJsonPath('code', 'tin_invalid');
    expect($this->issuer->refresh()->status)->toBe(IssuerStatus::Draft);
});

it('requires credentials first for own_credentials issuers and maps LHDN outages to 503', function () {
    $own = Issuer::factory()->for($this->tenant)->create(['lhdn_mode' => LhdnMode::OwnCredentials]);
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$own->id}/verify-tin")->assertStatus(409)->assertJsonPath('code', 'lhdn_credentials_invalid');
    fakeLhdn()->failNextWith(LhdnException::transient('down', 503));
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(503)->assertJsonPath('code', 'lhdn_unavailable');
});

it('authorizes after TIN verification and activates when a certificate exists', function () use ($certs) {
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertStatus(409)->assertJsonPath('code', 'tin_not_verified');
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertOk();
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertOk()->assertJsonPath('data.status', 'authorized');
    expect(collect(fakeLhdn()->calls())->last()['operation'])->toBe('token')->and(AuditLog::where('action', 'issuer.authorized')->count())->toBe(1);
    $this->withHeaders($this->h)->putJson("/v1/issuers/{$this->issuer->id}/certificate", ['format' => 'pem', 'certificate' => $certs('test-cert.pem'), 'private_key' => $certs('test-key.pem')])
        ->assertOk()->assertJsonPath('data.status', 'active');
});

it('reports credential failures during authorize as 409 lhdn_credentials_invalid', function () {
    $this->issuer->forceFill(['tin_verified_at' => now(), 'status' => IssuerStatus::TinVerified])->save();
    fakeLhdn()->failNextWith(LhdnException::auth('invalid_client', 401), 'token');
    $this->withHeaders($this->h)->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertStatus(409)->assertJsonPath('code', 'lhdn_credentials_invalid')
        ->assertJsonPath('detail', fn ($d) => str_contains($d, 'intermediary'));
    expect($this->issuer->refresh()->status)->toBe(IssuerStatus::TinVerified);
});

it('is tenant/environment scoped and needs issuers:manage', function () {
    $this->withHeaders(serviceHeaders(Tenant::factory()->create(), 'sandbox'))->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'production'))->postJson("/v1/issuers/{$this->issuer->id}/authorize")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'sandbox', ['read']))->postJson("/v1/issuers/{$this->issuer->id}/verify-tin")->assertStatus(403);
});
```

`tests/Feature/Lhdn/TinValidateTest.php`
```php
<?php

use App\Enums\Environment;
use App\Models\Issuer;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create(['environment' => Environment::Sandbox]);
    $this->h = apiKeyHeaders($this->tenant, 'sandbox');
});

it('validates a TIN, caches the result for 24h, and reports cache hits', function () {
    $body = ['tin' => 'C1234567890', 'id_type' => 'BRN', 'id_number' => '123456'];
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', $body)->assertOk()->assertJsonPath('data.valid', true)->assertJsonPath('data.cached', false);
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', $body)->assertOk()->assertJsonPath('data.cached', true);
    expect(collect(fakeLhdn()->calls())->where('operation', 'validate_tin'))->toHaveCount(1);
    fakeLhdn()->invalidTin('C0000000000');
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', ['tin' => 'C0000000000', 'id_type' => 'NRIC', 'id_number' => '900101011234'])->assertOk()->assertJsonPath('data.valid', false);
});

it('validates input and requires an issuer to exist', function () {
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', ['tin' => 'C1', 'id_type' => 'XX', 'id_number' => '1'])->assertStatus(422)->assertJsonFragment(['pointer' => '/id_type']);
    $empty = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($empty, 'sandbox'))->postJson('/v1/tin/validate', ['tin' => 'C1234567890', 'id_type' => 'BRN', 'id_number' => '1'])->assertStatus(409)->assertJsonPath('code', 'issuer_required');
});

it('scopes the cache per tenant and environment and respects abilities', function () {
    $body = ['tin' => 'C1234567890', 'id_type' => 'BRN', 'id_number' => '123456'];
    $this->withHeaders($this->h)->postJson('/v1/tin/validate', $body)->assertOk();
    $other = Tenant::factory()->create();
    Issuer::factory()->for($other)->active()->create();
    $this->withHeaders(apiKeyHeaders($other, 'sandbox'))->postJson('/v1/tin/validate', $body)->assertOk()->assertJsonPath('data.cached', false);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'sandbox', ['documents:write']))->postJson('/v1/tin/validate', $body)->assertStatus(403);
});
```

Sweep rows (`cross_tenant_routes`): `'issuer verify-tin' => [issuer factory, 'POST', '/v1/issuers/{id}/verify-tin']`, `'issuer authorize' => [..., 'POST', '/v1/issuers/{id}/authorize']`; `cross_environment_routes`: `'issuer verify-tin (prod issuer, test key)'`.

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement**

`ProblemResponse::describe()` addition (before `HttpExceptionInterface`):
```php
if ($e instanceof \App\Lhdn\LhdnException) {
    return match ($e->kind) {
        \App\Lhdn\LhdnErrorKind::Auth => [409, 'Conflict', $e->getMessage(), 'lhdn_credentials_invalid', []],
        \App\Lhdn\LhdnErrorKind::Terminal => [422, 'Unprocessable Entity', $e->getMessage(), 'lhdn_rejected', []],
        default => [503, 'Service Unavailable', $e->getMessage(), 'lhdn_unavailable', []],
    };
}
```

`app/Actions/Issuers/VerifyIssuerTin.php`
```php
<?php

namespace App\Actions\Issuers;

use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Exceptions\ProblemException;
use App\Lhdn\LhdnClientFactory;
use App\Models\Issuer;
use App\Services\Audit\AuditLogger;

class VerifyIssuerTin
{
    public function __construct(private readonly LhdnClientFactory $clients, private readonly AuditLogger $audit) {}

    public function handle(Issuer $issuer): Issuer
    {
        $client = $issuer->lhdn_mode === LhdnMode::OwnCredentials
            ? $this->clients->for($issuer)
            : $this->clients->forEnvironment($issuer->environment);
        $valid = $client->validateTin($issuer->environment, $issuer->tin, $issuer->id_type->value, $issuer->id_number, $issuer);
        $this->audit->record('issuer.tin_verified', $issuer, ['tin_verified' => $valid]);
        if (! $valid) {
            throw new ProblemException(422, 'Unprocessable Entity', 'LHDN does not recognise this TIN / ID combination.', 'tin_invalid');
        }
        $issuer->tin_verified_at = now();
        if ($issuer->status === IssuerStatus::Draft) {
            $issuer->status = IssuerStatus::TinVerified;
        }
        $issuer->save();

        return $issuer->refresh();
    }
}
```

`app/Actions/Issuers/AuthorizeIssuer.php`
```php
<?php

namespace App\Actions\Issuers;

use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Exceptions\ProblemException;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Services\Audit\AuditLogger;
use App\Services\Issuers\IssuerActivator;

class AuthorizeIssuer
{
    public function __construct(private readonly LhdnClientFactory $clients, private readonly IssuerActivator $activator, private readonly AuditLogger $audit) {}

    public function handle(Issuer $issuer): Issuer
    {
        if ($issuer->tin_verified_at === null) {
            throw ProblemException::conflict('Verify the issuer TIN before authorising LHDN access.', 'tin_not_verified');
        }
        try {
            $this->clients->for($issuer)->token($issuer);
        } catch (LhdnException $e) {
            if ($e->kind === LhdnErrorKind::Auth && $issuer->lhdn_mode === LhdnMode::Intermediary) {
                throw LhdnException::auth($e->getMessage().' Ask the merchant to grant Billplz intermediary access to this TIN in MyInvois, then retry.', $e->httpStatus);
            }
            throw $e;
        }
        $issuer->authorized_at = now();
        if (in_array($issuer->status, [IssuerStatus::Draft, IssuerStatus::TinVerified], true)) {
            $issuer->status = IssuerStatus::Authorized;
        }
        $issuer->save();
        $issuer->secret?->forceFill(['credentials_verified_at' => now()])->save();
        $this->activator->apply($issuer);
        $this->audit->record('issuer.authorized', $issuer, ['lhdn_mode' => $issuer->lhdn_mode->value, 'status' => $issuer->status->value]);

        return $issuer->refresh();
    }
}
```

`app/Data/Requests/Tin/ValidateTinData.php`
```php
<?php

namespace App\Data\Requests\Tin;

use App\Enums\IdType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class ValidateTinData extends Data
{
    public function __construct(
        #[Max(20)] public string $tin,
        public IdType $id_type,
        #[Max(30)] public string $id_number,
        #[Max(26)] public ?string $issuer_id = null,
    ) {}
}
```

`app/Data/Resources/TinValidationData.php`
```php
<?php

namespace App\Data\Resources;

use Spatie\LaravelData\Data;

class TinValidationData extends Data
{
    public function __construct(
        public string $tin,
        public string $id_type,
        public string $id_number,
        public bool $valid,
        public string $checked_at,
        public bool $cached,
    ) {}
}
```

`app/Actions/Tin/ValidateTin.php`
```php
<?php

namespace App\Actions\Tin;

use App\Data\Requests\Tin\ValidateTinData;
use App\Data\Resources\TinValidationData;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Lhdn\LhdnClientFactory;
use App\Models\Issuer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

class ValidateTin
{
    public function __construct(private readonly LhdnClientFactory $clients, private readonly TenantContext $context) {}

    public function handle(ValidateTinData $data): TinValidationData
    {
        $env = $this->context->environment();
        $key = 'tin:'.$this->context->tenant()->getKey().':'.$env->value.':'.sha1($data->tin.'|'.$data->id_type->value.'|'.$data->id_number);
        /** @var array{valid: bool, checked_at: string}|null $cached */
        $cached = Cache::get($key);
        if ($cached !== null) {
            return new TinValidationData($data->tin, $data->id_type->value, $data->id_number, $cached['valid'], $cached['checked_at'], true);
        }
        $issuer = $this->resolveIssuer($data->issuer_id);
        $valid = $this->clients->for($issuer)->validateTin($env, $data->tin, $data->id_type->value, $data->id_number, $issuer);
        $checkedAt = now()->toIso8601String();
        Cache::put($key, ['valid' => $valid, 'checked_at' => $checkedAt], now()->addHours((int) config('lhdn.tin_cache_hours', 24)));

        return new TinValidationData($data->tin, $data->id_type->value, $data->id_number, $valid, $checkedAt, false);
    }

    private function resolveIssuer(?string $issuerId): Issuer
    {
        if ($issuerId !== null) {
            return Issuer::forCurrentEnvironment()->find($issuerId) ?? throw new ProblemException(404, 'Not Found', 'Issuer not found.', 'issuer_not_found');
        }

        return Issuer::forCurrentEnvironment()->where('status', IssuerStatus::Active)->orderBy('created_at')->first()
            ?? Issuer::forCurrentEnvironment()->orderBy('created_at')->first()
            ?? throw ProblemException::conflict('Create an issuer in this environment before validating TINs.', 'issuer_required');
    }
}
```
(When the resolved issuer is in intermediary mode, `factory->for()` yields the intermediary client with `onbehalfof` = that issuer's TIN — acceptable; LHDN's validate-TIN endpoint is taxpayer-agnostic.)

`app/Http/Controllers/Api/V1/IssuerOnboardingController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Issuers\AuthorizeIssuer;
use App\Actions\Issuers\VerifyIssuerTin;
use App\Data\Resources\IssuerData;
use App\Http\Controllers\Controller;
use App\Models\Issuer;
use Illuminate\Http\JsonResponse;

class IssuerOnboardingController extends Controller
{
    public function verifyTin(Issuer $issuer, VerifyIssuerTin $verify): JsonResponse
    {
        return response()->json(['data' => IssuerData::fromModel($verify->handle($issuer)->load('secret'))->toArray()]);
    }

    public function authorize(Issuer $issuer, AuthorizeIssuer $authorize): JsonResponse
    {
        return response()->json(['data' => IssuerData::fromModel($authorize->handle($issuer)->load('secret'))->toArray()]);
    }
}
```

`app/Http/Controllers/Api/V1/TinController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tin\ValidateTin;
use App\Data\Requests\Tin\ValidateTinData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TinController extends Controller
{
    public function validate(ValidateTinData $data, ValidateTin $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($data)->toArray()]);
    }
}
```

Routes — inside `tenant` group: under `ability:issuers:manage`: `Route::post('/issuers/{issuer}/verify-tin', [IssuerOnboardingController::class, 'verifyTin']); Route::post('/issuers/{issuer}/authorize', [IssuerOnboardingController::class, 'authorize']);` under `ability:read`: `Route::post('/tin/validate', [TinController::class, 'validate']);`.

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Feature/Lhdn tests/Feature/TenantIsolationSweepTest.php tests/Feature/IssuerSecretsTest.php` → pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(issuers): LHDN TIN verification, authorisation, and POST /tin/validate with cache"
```

---

### Task 7: Submission pipeline — `PrepareDocument`, `SubmitDocuments`, `PollSubmission`, scheduler sweep

**Files:**
- Create: `app/Jobs/PrepareDocument.php`, `app/Jobs/SubmitDocuments.php`, `app/Jobs/PollSubmission.php`, `app/Listeners/PrepareDocumentOnQueued.php`, `app/Console/Commands/LhdnDispatch.php`, `app/Lhdn/Pipeline/SubmissionErrors.php` (error → `lhdn_errors` array + message helpers), `tests/Feature/Lhdn/SubmissionPipelineTest.php`
- Modify: `app/Providers/AppServiceProvider.php` (listener registration), `routes/console.php` (schedule), `app/Data/Resources/DocumentData.php` (`lhdn.validation_url`)

**Interfaces:**
- Consumes: Tasks 1–5; `TenantAwareJob`/`Queueable`; `DocumentStateMachine`; `LhdnClientFactory`; `SigningMaterial`; `UblDocumentBuilder`; `DocumentSigner`; `LhdnException`; `HeldReason`; `DocumentTransitioned`.
- Produces:
  - Listener `PrepareDocumentOnQueued::handle(DocumentTransitioned $e)`: when `$e->to === Queued` → `PrepareDocument::dispatch($e->document->id)`. Registered explicitly in `AppServiceProvider::boot()`.
  - `PrepareDocument(string $documentId)` (tenant-aware, `Queueable`, `tries 3`): loads the document (`with lines, issuer.secret, originalDocument`); skip unless status `queued`; if issuer not active → hold `IssuerNotActive`; no valid cert → hold `CertificateExpired`; else `ubl = builder->build($doc)`, `signed = signer->sign($ubl, SigningMaterial::fromSecret($secret))`; if `strlen(signed->json) > max_document_bytes` → transition `invalid` (`lhdn_errors = [{code:'DOC_TOO_LARGE', message}]`, reason `document_too_large`); else `forceFill(['ubl_json' => signed->json, 'signed_payload_hash' => signed->hashHex])->save()`; then `SubmitDocuments::dispatch($doc->issuer_id)`. Signing failures (`LhdnException` Auth) → hold `CertificateExpired` with `last_submission_error`.
  - `SubmitDocuments(string $issuerId)` (tenant-aware, `Queueable`, middleware `WithoutOverlapping("lhdn-submit:{$issuerId}")->releaseAfter(30)->expireAfter(300)`, `tries 1` — re-dispatch is driven by the job itself and the scheduler): select up to `max_documents` `queued` documents of the issuer with `ubl_json IS NOT NULL` and (`next_submission_at IS NULL OR <= now`) ordered by `created_at`, accumulate while total bytes ≤ `max_bytes`; none → return. Build `SubmissionBatch` from `lhdn_internal_id`+`ubl_json`. Call `client->submitDocuments()`:
    - success: for each accepted → `forceFill(['lhdn_uuid' => uuid, 'lhdn_submission_uid' => uid, 'submission_attempts_count' => +1, 'last_submission_error' => null, 'next_submission_at' => null])` then `transition(Submitted)`; for each rejected → `forceFill(['lhdn_errors' => [[code, message]], 'last_submission_error' => {...}])` then `transition(Invalid, 'rejected_at_submission', ['errors' => …])`; then `PollSubmission::dispatch($issuerId, $uid)->delay(config poll backoff[0])`; if more eligible documents remain → `self::dispatch($issuerId)`.
    - `LhdnException` Transient/Breaker: for each document in the batch: `submission_attempts_count++`, `last_submission_error = {kind, message, at}`; if count ≥ `max_attempts` → `transition(Held, heldReason: LhdnUnavailable)`; else `next_submission_at = now + retry_backoff_seconds[min(count-1, last)]`; then `self::dispatch($issuerId)->delay(that backoff)` (one job, not per document).
    - Auth: each document → `transition(Held, heldReason: LhdnCredentialsInvalid)` + `last_submission_error`; no re-dispatch.
    - Terminal (whole-batch 4xx, e.g. payload schema): each document → `transition(Invalid, 'rejected_at_submission', ['errors' => [[code:'LHDN_'.status, message]]])` with `lhdn_errors` set.
  - `PollSubmission(string $issuerId, string $submissionUid, int $attempt = 0)` (tenant-aware, `Queueable`, `tries` = count(backoff)+1): `client->getSubmission()`; for each summary matching a `submitted` document by `lhdn_uuid`: `Valid` → `forceFill(['lhdn_long_id' => longId])` + `transition(Valid)`; `Invalid` → fetch `getDocument()` for errors → `forceFill(['lhdn_errors' => errors])` + `transition(Invalid, 'rejected_by_lhdn', ['errors' => …])`; `Cancelled` → `transition(Cancelled, 'cancelled_at_lhdn')` (only if currently `valid`; if `submitted` treat as invalid with message) — simplification: documents seen `Cancelled` while `submitted` → `Invalid` with code `CANCELLED_AT_LHDN`; `Rejected` → if `valid` → `transition(Rejected, 'buyer_rejected')`; `Submitted`/in progress → nothing. If `!status->isFinal()` or any of our documents still `submitted` → `self::dispatch($issuerId, $uid, $attempt+1)->delay(backoff[min($attempt+1, last)])` unless `$attempt+1 > count(backoff)` (then stop; the scheduler sweep re-dispatches). `LhdnException` Transient/Breaker → same re-dispatch with backoff; Auth → stop (documents stay `submitted`; sweep retries later); Terminal (e.g. unknown uid 404) → mark affected docs `invalid` with `LHDN_POLL_{status}`.
  - `LhdnDispatch` command `einvoice:lhdn-dispatch` (scheduled `everyMinute()` in `routes/console.php`): for each tenant (`Tenant::query()->cursor()` — system job; bind `TenantContext` with a `system` actor per tenant, both environments): dispatch `SubmitDocuments` for each issuer having eligible queued+prepared docs; dispatch `PrepareDocument` for `queued` docs with `ubl_json IS NULL` older than 1 minute (lost prepares); dispatch `PollSubmission` (attempt 0) for distinct `lhdn_submission_uid` of `submitted` docs whose `submitted_at` < now−2 minutes (dedupe via `WithoutOverlapping("lhdn-poll:{uid}")` middleware on `PollSubmission`). Clear context after each tenant.
  - `DocumentData.lhdn` gains `validation_url` = `"{portal_base}/{uuid}/share/{long_id}"` when both present (env-aware via `config('lhdn.environments.{env}.portal_base')`).
  - `SubmissionErrors::fromException(LhdnException $e): list<array{code:string,message:string}>` and `::fromRejection(array $r)`.

- [ ] **Step 1: Write failing tests `tests/Feature/Lhdn/SubmissionPipelineTest.php`**

```php
<?php

use App\Actions\Documents\CreateDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Jobs\PollSubmission;
use App\Jobs\PrepareDocument;
use App\Jobs\SubmitDocuments;
use App\Lhdn\LhdnException;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

$certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

function pipelineDoc(Issuer $issuer, array $overrides = []): Document
{
    $payload = array_replace_recursive([
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true],
        'lines' => [['classification_code' => '022', 'description' => 'Widget', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => '10.00', 'tax_type' => '02', 'tax_rate' => 6]],
        'source' => ['system' => 'test', 'ref' => 'ref-'.bin2hex(random_bytes(4))],
    ], $overrides);

    return app(CreateDocument::class)->handle(CreateDocumentData::from($payload))->document->refresh();
}

beforeEach(function () use ($certs) {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->issuer->secret()->create(['signing_certificate' => $certs('test-cert.pem'), 'signing_key' => $certs('test-key.pem'), 'cert_not_after' => now()->addYears(5)]);
});

it('runs queued → prepared → submitted → valid end-to-end on the sync queue', function () {
    $doc = pipelineDoc($this->issuer); // sync queue: listener → PrepareDocument → SubmitDocuments → PollSubmission run inline
    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Valid)
        ->and($doc->ubl_json)->not->toBeNull()
        ->and($doc->signed_payload_hash)->toHaveLength(64)
        ->and($doc->lhdn_uuid)->not->toBeNull()
        ->and($doc->lhdn_long_id)->not->toBeNull()
        ->and($doc->lhdn_submission_uid)->toStartWith('SUB-')
        ->and($doc->events()->pluck('to_status')->map->value->all())->toBe(['validated', 'queued', 'submitted', 'valid']);
    $ops = collect(fakeLhdn()->calls())->pluck('operation')->all();
    expect($ops)->toContain('submit', 'get_submission');
    expect(\App\Models\SubmissionAttempt::count())->toBe(0); // fake client does not record; HttpLhdnClient does (Task 3)
});

it('marks documents invalid when LHDN rejects them at submission or after validation', function () {
    fakeLhdn()->pollsUntilFinal(0);
    $rejectedRef = 'will-reject';
    // Reject by internal id: we need the document id before submit; create with submit=false then set rejection, then submit.
    $doc = pipelineDoc($this->issuer, ['submit' => false]);
    fakeLhdn()->rejectDocument($doc->lhdn_internal_id, 'CF321', 'Schema error');
    app(\App\Actions\Documents\SubmitDocument::class)->handle($doc);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Invalid)->and($doc->lhdn_errors[0]['code'])->toBe('CF321');

    Queue::fake([PollSubmission::class]);
    $doc2 = pipelineDoc($this->issuer);
    expect($doc2->refresh()->status)->toBe(DocumentStatus::Submitted);
    fakeLhdn()->markInvalid($doc2->lhdn_uuid, [['code' => 'DS302', 'message' => 'tax mismatch']]);
    (new PollSubmission($this->issuer->id, $doc2->lhdn_submission_uid))->handle(app(\App\Lhdn\LhdnClientFactory::class), app(\App\Domain\Documents\DocumentStateMachine::class));
    expect($doc2->refresh()->status)->toBe(DocumentStatus::Invalid)->and($doc2->lhdn_errors[0]['code'])->toBe('DS302');
});

it('holds documents when the issuer is not ready and when LHDN is down for too long', function () {
    $this->issuer->secret()->delete();
    $doc = pipelineDoc($this->issuer->refresh());
    expect($doc->refresh()->status)->toBe(DocumentStatus::Held)->and($doc->held_reason)->toBe(HeldReason::CertificateExpired);

    $this->issuer->secret()->create(['signing_certificate' => file_get_contents(base_path('tests/Fixtures/certs/test-cert.pem')), 'signing_key' => file_get_contents(base_path('tests/Fixtures/certs/test-key.pem'))]);
    config(['lhdn.submission.max_attempts' => 2, 'lhdn.submission.retry_backoff_seconds' => [1, 1]]);
    Queue::fake([SubmitDocuments::class, PollSubmission::class]);
    $d2 = pipelineDoc($this->issuer->refresh()); // prepared synchronously; SubmitDocuments faked
    expect($d2->refresh()->status)->toBe(DocumentStatus::Queued)->and($d2->ubl_json)->not->toBeNull();
    fakeLhdn()->failNextWith(LhdnException::transient('down', 503), 'submit');
    (new SubmitDocuments($this->issuer->id))->handle(app(\App\Lhdn\LhdnClientFactory::class), app(\App\Domain\Documents\DocumentStateMachine::class));
    expect($d2->refresh()->status)->toBe(DocumentStatus::Queued)->and($d2->submission_attempts_count)->toBe(1)->and($d2->next_submission_at)->not->toBeNull();
    Queue::assertPushed(SubmitDocuments::class);
    $d2->forceFill(['next_submission_at' => now()->subSecond()])->save();
    fakeLhdn()->failNextWith(LhdnException::transient('down', 503), 'submit');
    (new SubmitDocuments($this->issuer->id))->handle(app(\App\Lhdn\LhdnClientFactory::class), app(\App\Domain\Documents\DocumentStateMachine::class));
    expect($d2->refresh()->status)->toBe(DocumentStatus::Held)->and($d2->held_reason)->toBe(HeldReason::LhdnUnavailable);

    $d3 = pipelineDoc($this->issuer);
    fakeLhdn()->failNextWith(LhdnException::auth('bad creds', 401), 'submit');
    (new SubmitDocuments($this->issuer->id))->handle(app(\App\Lhdn\LhdnClientFactory::class), app(\App\Domain\Documents\DocumentStateMachine::class));
    expect($d3->refresh()->held_reason)->toBe(HeldReason::LhdnCredentialsInvalid);
});

it('keeps polling with backoff while LHDN is in progress, and the scheduler sweep re-dispatches stragglers', function () {
    Queue::fake([PollSubmission::class]);
    fakeLhdn()->pollsUntilFinal(1);
    $doc = pipelineDoc($this->issuer);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted);
    (new PollSubmission($this->issuer->id, $doc->lhdn_submission_uid, 0))->handle(app(\App\Lhdn\LhdnClientFactory::class), app(\App\Domain\Documents\DocumentStateMachine::class));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Submitted);
    Queue::assertPushed(PollSubmission::class, fn ($j) => $j->attempt === 1);
    (new PollSubmission($this->issuer->id, $doc->lhdn_submission_uid, 1))->handle(app(\App\Lhdn\LhdnClientFactory::class), app(\App\Domain\Documents\DocumentStateMachine::class));
    expect($doc->refresh()->status)->toBe(DocumentStatus::Valid);

    Queue::fake([SubmitDocuments::class, PollSubmission::class, PrepareDocument::class]);
    $straggler = Document::factory()->for($this->issuer)->queued()->create(['ubl_json' => '{}', 'lhdn_internal_id' => 'STRAG1']);
    $submittedOld = Document::factory()->for($this->issuer)->create(['status' => 'submitted', 'lhdn_submission_uid' => 'SUB-OLD', 'lhdn_uuid' => 'U-OLD', 'submitted_at' => now()->subMinutes(10), 'lhdn_internal_id' => 'OLD1']);
    $unprepared = Document::factory()->for($this->issuer)->queued()->create(['lhdn_internal_id' => 'UNP1', 'created_at' => now()->subMinutes(5)]);
    app(TenantContext::class)->clear();
    Artisan::call('einvoice:lhdn-dispatch');
    Queue::assertPushed(SubmitDocuments::class, fn ($j) => $j->issuerId === $this->issuer->id);
    Queue::assertPushed(PollSubmission::class, fn ($j) => $j->submissionUid === 'SUB-OLD');
    Queue::assertPushed(PrepareDocument::class, fn ($j) => $j->documentId === $unprepared->id);
});

it('exposes the LHDN validation url once valid', function () {
    config(['lhdn.environments.sandbox.portal_base' => 'https://preprod.myinvois.hasil.gov.my']);
    $doc = pipelineDoc($this->issuer)->refresh();
    $data = \App\Data\Resources\DocumentData::fromModel($doc)->toArray();
    expect($data['lhdn']['validation_url'])->toBe("https://preprod.myinvois.hasil.gov.my/{$doc->lhdn_uuid}/share/{$doc->lhdn_long_id}");
});
```
Note on the sync queue: in tests `QUEUE_CONNECTION=sync`, so `dispatch()` runs inline; `->delay()` is ignored on sync. The chain listener→Prepare→Submit→Poll therefore completes inside `CreateDocument::handle()`; the state machine transitions happen inside nested `DB::transaction`s — `DocumentTransitioned` is after-commit, and in tests the transaction manager fires after-commit callbacks at the inner commit (verified in Plan 2), so the listener runs right after the `queued` transition commits. `CreateDocument::route()` runs inside the outer `DB::transaction` — the `queued` transition's after-commit callbacks fire when the OUTER transaction commits (Plan 2 testing manager fires at level 1 = the RefreshDatabase wrapper's child) — verify empirically in Step 4; if the listener fires while the outer transaction is still open, PrepareDocument's fresh query still sees the row (same connection), so it works either way.

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement**

`app/Lhdn/Pipeline/SubmissionErrors.php`
```php
<?php

namespace App\Lhdn\Pipeline;

use App\Lhdn\LhdnException;

final class SubmissionErrors
{
    /** @return list<array{code: string, message: string}> */
    public static function fromException(LhdnException $e): array
    {
        return [['code' => 'LHDN_'.($e->httpStatus ?? strtoupper($e->kind->value)), 'message' => mb_substr($e->getMessage(), 0, 500)]];
    }

    /** @param array{code: string, message: string} $rejection @return list<array{code: string, message: string}> */
    public static function fromRejection(array $rejection): array
    {
        return [['code' => $rejection['code'], 'message' => mb_substr($rejection['message'], 0, 500)]];
    }

    /** @return array{kind: string, message: string, at: string} */
    public static function summary(LhdnException $e): array
    {
        return ['kind' => $e->kind->value, 'message' => mb_substr($e->getMessage(), 0, 500), 'at' => now()->toIso8601String()];
    }
}
```

`app/Listeners/PrepareDocumentOnQueued.php`
```php
<?php

namespace App\Listeners;

use App\Enums\DocumentStatus;
use App\Events\DocumentTransitioned;
use App\Jobs\PrepareDocument;

class PrepareDocumentOnQueued
{
    public function handle(DocumentTransitioned $event): void
    {
        if ($event->to === DocumentStatus::Queued) {
            PrepareDocument::dispatch($event->document->id);
        }
    }
}
```
Register in `AppServiceProvider::boot()`: `Event::listen(DocumentTransitioned::class, PrepareDocumentOnQueued::class);`

`app/Jobs/PrepareDocument.php`
```php
<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
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

class PrepareDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 3;

    public function __construct(public readonly string $documentId)
    {
        $this->captureTenantContext();
    }

    public function handle(UblDocumentBuilder $builder, DocumentSigner $signer, DocumentStateMachine $sm): void
    {
        $document = Document::query()->with(['lines', 'issuer.secret', 'originalDocument'])->find($this->documentId);
        if ($document === null || $document->status !== DocumentStatus::Queued) {
            return;
        }
        $issuer = $document->issuer;
        if ($issuer->status !== IssuerStatus::Active) {
            $sm->transition($document, DocumentStatus::Held, heldReason: HeldReason::IssuerNotActive);

            return;
        }
        if (! $issuer->hasValidCertificate() || $issuer->secret === null || ! $issuer->secret->hasCertificate()) {
            $sm->transition($document, DocumentStatus::Held, heldReason: HeldReason::CertificateExpired);

            return;
        }
        try {
            $signed = $signer->sign($builder->build($document), SigningMaterial::fromSecret($issuer->secret));
        } catch (LhdnException $e) {
            $document->forceFill(['last_submission_error' => SubmissionErrors::summary($e)])->save();
            $sm->transition($document, DocumentStatus::Held, heldReason: HeldReason::CertificateExpired);

            return;
        }
        $max = (int) config('lhdn.submission.max_document_bytes', 307200);
        if (strlen($signed->json) > $max) {
            $document->forceFill(['lhdn_errors' => [['code' => 'DOC_TOO_LARGE', 'message' => "Signed document is ".strlen($signed->json)." bytes; LHDN limit is {$max}."]]])->save();
            $sm->transition($document, DocumentStatus::Invalid, 'document_too_large');

            return;
        }
        $document->forceFill(['ubl_json' => $signed->json, 'signed_payload_hash' => $signed->hashHex, 'lhdn_internal_id' => $document->lhdn_internal_id ?? $document->id])->save();
        SubmitDocuments::dispatch($issuer->id);
    }
}
```

`app/Jobs/SubmitDocuments.php`
```php
<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionDocument;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Lhdn\Pipeline\SubmissionErrors;
use App\Models\Document;
use App\Models\Issuer;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;

class SubmitDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct(public readonly string $issuerId)
    {
        $this->captureTenantContext();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [...$this->tenantMiddleware(), (new WithoutOverlapping("lhdn-submit:{$this->issuerId}"))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(LhdnClientFactory $clients, DocumentStateMachine $sm): void
    {
        $issuer = Issuer::query()->find($this->issuerId);
        if ($issuer === null) {
            return;
        }
        $docs = $this->eligible($issuer);
        if ($docs->isEmpty()) {
            return;
        }
        $batch = new SubmissionBatch($docs->map(fn (Document $d) => SubmissionDocument::fromJson((string) $d->lhdn_internal_id, (string) $d->ubl_json))->values()->all());

        try {
            $result = $clients->for($issuer)->submitDocuments($issuer, $batch);
        } catch (LhdnException $e) {
            $this->handleFailure($docs, $e, $sm);

            return;
        }

        foreach ($docs as $doc) {
            $internal = (string) $doc->lhdn_internal_id;
            if (isset($result->acceptedUuidsByInternalId[$internal])) {
                $doc->forceFill([
                    'lhdn_uuid' => $result->acceptedUuidsByInternalId[$internal],
                    'lhdn_submission_uid' => $result->submissionUid,
                    'submission_attempts_count' => $doc->submission_attempts_count + 1,
                    'last_submission_error' => null,
                    'next_submission_at' => null,
                ])->save();
                $sm->transition($doc, DocumentStatus::Submitted);
            } elseif (isset($result->rejectedByInternalId[$internal])) {
                $errors = SubmissionErrors::fromRejection($result->rejectedByInternalId[$internal]);
                $doc->forceFill(['lhdn_errors' => $errors, 'submission_attempts_count' => $doc->submission_attempts_count + 1])->save();
                $sm->transition($doc, DocumentStatus::Invalid, 'rejected_at_submission', ['errors' => $errors]);
            }
        }
        if ($result->acceptedUuidsByInternalId !== []) {
            $delays = (array) config('lhdn.poll.backoff_seconds', [5]);
            PollSubmission::dispatch($issuer->id, $result->submissionUid)->delay(now()->addSeconds((int) $delays[0]));
        }
        if ($this->eligible($issuer)->isNotEmpty()) {
            self::dispatch($issuer->id);
        }
    }

    /** @return Collection<int, Document> */
    private function eligible(Issuer $issuer): Collection
    {
        $maxDocs = (int) config('lhdn.submission.max_documents', 100);
        $maxBytes = (int) config('lhdn.submission.max_bytes', 5242880);
        $candidates = Document::query()
            ->where('issuer_id', $issuer->id)
            ->where('status', DocumentStatus::Queued)
            ->whereNotNull('ubl_json')
            ->where(fn ($q) => $q->whereNull('next_submission_at')->orWhere('next_submission_at', '<=', now()))
            ->orderBy('created_at')->orderBy('id')
            ->limit($maxDocs)
            ->get();
        $bytes = 0;
        $out = collect();
        foreach ($candidates as $doc) {
            $size = strlen((string) $doc->ubl_json);
            if ($out->isNotEmpty() && $bytes + $size > $maxBytes) {
                break;
            }
            $bytes += $size;
            $out->push($doc);
        }

        return $out;
    }

    /** @param Collection<int, Document> $docs */
    private function handleFailure(Collection $docs, LhdnException $e, DocumentStateMachine $sm): void
    {
        $summary = SubmissionErrors::summary($e);
        $errors = SubmissionErrors::fromException($e);
        $maxAttempts = (int) config('lhdn.submission.max_attempts', 8);
        $backoffs = (array) config('lhdn.submission.retry_backoff_seconds', [60]);
        $delay = null;
        foreach ($docs as $doc) {
            $count = $doc->submission_attempts_count + 1;
            match ($e->kind) {
                LhdnErrorKind::Auth => (function () use ($doc, $summary, $sm): void {
                    $doc->forceFill(['last_submission_error' => $summary])->save();
                    $sm->transition($doc, DocumentStatus::Held, heldReason: HeldReason::LhdnCredentialsInvalid);
                })(),
                LhdnErrorKind::Terminal => (function () use ($doc, $summary, $errors, $count, $sm): void {
                    $doc->forceFill(['last_submission_error' => $summary, 'lhdn_errors' => $errors, 'submission_attempts_count' => $count])->save();
                    $sm->transition($doc, DocumentStatus::Invalid, 'rejected_at_submission', ['errors' => $errors]);
                })(),
                default => (function () use ($doc, $summary, $count, $maxAttempts, $backoffs, $sm, &$delay): void {
                    if ($count >= $maxAttempts) {
                        $doc->forceFill(['last_submission_error' => $summary, 'submission_attempts_count' => $count])->save();
                        $sm->transition($doc, DocumentStatus::Held, heldReason: HeldReason::LhdnUnavailable);

                        return;
                    }
                    $seconds = (int) $backoffs[min($count - 1, count($backoffs) - 1)];
                    $delay = max($delay ?? 0, $seconds);
                    $doc->forceFill(['last_submission_error' => $summary, 'submission_attempts_count' => $count, 'next_submission_at' => now()->addSeconds($seconds)])->save();
                })(),
            };
        }
        if ($delay !== null) {
            self::dispatch($this->issuerId)->delay(now()->addSeconds($delay));
        }
    }
}
```
`TenantAwareJob::middleware()` currently returns `[new BindTenantContext]`; add a protected helper `tenantMiddleware(): array` returning the same so jobs that add their own middleware can compose (edit the trait: `public function middleware(): array { return $this->tenantMiddleware(); } protected function tenantMiddleware(): array { return [new BindTenantContext]; }`). `WithoutOverlapping` must come AFTER the tenant binding (order as written).

`app/Jobs/PollSubmission.php`
```php
<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Lhdn\Data\DocumentSummary;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Lhdn\Pipeline\SubmissionErrors;
use App\Models\Document;
use App\Models\Issuer;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PollSubmission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct(public readonly string $issuerId, public readonly string $submissionUid, public readonly int $attempt = 0)
    {
        $this->captureTenantContext();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [...$this->tenantMiddleware(), (new WithoutOverlapping("lhdn-poll:{$this->submissionUid}"))->dontRelease()->expireAfter(120)];
    }

    public function handle(LhdnClientFactory $clients, DocumentStateMachine $sm): void
    {
        $issuer = Issuer::query()->find($this->issuerId);
        if ($issuer === null) {
            return;
        }
        $pending = Document::query()->where('issuer_id', $issuer->id)->where('lhdn_submission_uid', $this->submissionUid)
            ->whereIn('status', [DocumentStatus::Submitted, DocumentStatus::Valid])->get()->keyBy('lhdn_uuid');
        if ($pending->isEmpty()) {
            return;
        }
        $client = $clients->for($issuer);
        try {
            $status = $client->getSubmission($issuer, $this->submissionUid);
            foreach ($status->documents as $summary) {
                /** @var Document|null $doc */
                $doc = $pending->get($summary->uuid);
                if ($doc === null) {
                    continue;
                }
                $this->apply($doc, $summary, $issuer, $clients, $sm);
            }
        } catch (LhdnException $e) {
            if ($e->kind === LhdnErrorKind::Terminal) {
                foreach ($pending->where('status', DocumentStatus::Submitted) as $doc) {
                    $errors = SubmissionErrors::fromException($e);
                    $doc->forceFill(['lhdn_errors' => $errors])->save();
                    $sm->transition($doc, DocumentStatus::Invalid, 'rejected_by_lhdn', ['errors' => $errors]);
                }

                return;
            }
            if ($e->kind === LhdnErrorKind::Auth) {
                return; // sweep retries later
            }
            $this->reschedule();

            return;
        }
        $stillSubmitted = Document::query()->where('lhdn_submission_uid', $this->submissionUid)->where('status', DocumentStatus::Submitted)->exists();
        if (! $status->isFinal() || $stillSubmitted) {
            $this->reschedule();
        }
    }

    private function apply(Document $doc, DocumentSummary $summary, Issuer $issuer, LhdnClientFactory $clients, DocumentStateMachine $sm): void
    {
        $state = strtolower($summary->status);
        if ($doc->status === DocumentStatus::Submitted) {
            if ($state === 'valid') {
                $doc->forceFill(['lhdn_long_id' => $summary->longId])->save();
                $sm->transition($doc, DocumentStatus::Valid);
            } elseif ($state === 'invalid' || $state === 'cancelled') {
                $errors = $summary->errors;
                if ($errors === [] && $state === 'invalid') {
                    try {
                        $errors = $clients->for($issuer)->getDocument($issuer, $summary->uuid)->validationErrors;
                    } catch (LhdnException $e) {
                        $errors = SubmissionErrors::fromException($e);
                    }
                }
                if ($errors === []) {
                    $errors = [['code' => $state === 'cancelled' ? 'CANCELLED_AT_LHDN' : 'INVALID', 'message' => 'LHDN reported the document as '.$summary->status.'.']];
                }
                $doc->forceFill(['lhdn_errors' => $errors, 'lhdn_long_id' => $summary->longId])->save();
                $sm->transition($doc, DocumentStatus::Invalid, 'rejected_by_lhdn', ['errors' => $errors]);
            }

            return;
        }
        // already valid: react to later LHDN states
        if ($state === 'rejected') {
            $sm->transition($doc, DocumentStatus::Rejected, 'buyer_rejected');
        } elseif ($state === 'cancelled' && $doc->isCancellable()) {
            $sm->transition($doc, DocumentStatus::Cancelled, 'cancelled_at_lhdn');
        }
    }

    private function reschedule(): void
    {
        $backoffs = (array) config('lhdn.poll.backoff_seconds', [5]);
        $next = $this->attempt + 1;
        if ($next >= count($backoffs)) {
            return; // give up for now; einvoice:lhdn-dispatch will poll again
        }
        self::dispatch($this->issuerId, $this->submissionUid, $next)->delay(now()->addSeconds((int) $backoffs[$next]));
    }
}
```
(`transition(Cancelled)` from `valid` requires `isCancellable()` — when LHDN reports cancelled after the window (LHDN allows issuer cancellation only within 72h, so this is consistent).)

`app/Console/Commands/LhdnDispatch.php`
```php
<?php

namespace App\Console\Commands;

use App\Auth\Actor;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Jobs\PollSubmission;
use App\Jobs\PrepareDocument;
use App\Jobs\SubmitDocuments;
use App\Models\Document;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

class LhdnDispatch extends Command
{
    protected $signature = 'einvoice:lhdn-dispatch';

    protected $description = 'Sweep for documents that need preparing, submitting or polling and dispatch the pipeline jobs.';

    public function handle(TenantContext $context): int
    {
        $dispatched = 0;
        foreach (Tenant::query()->cursor() as $tenant) {
            foreach (Environment::cases() as $env) {
                $context->bind($tenant, new Actor('system', 'einvoice:lhdn-dispatch', 'lhdn-dispatch', ['*']), $env);
                try {
                    $base = Document::query()->where('environment', $env);
                    foreach ((clone $base)->where('status', DocumentStatus::Queued)->whereNotNull('ubl_json')->where(fn ($q) => $q->whereNull('next_submission_at')->orWhere('next_submission_at', '<=', now()))->distinct()->pluck('issuer_id') as $issuerId) {
                        SubmitDocuments::dispatch($issuerId);
                        $dispatched++;
                    }
                    foreach ((clone $base)->where('status', DocumentStatus::Queued)->whereNull('ubl_json')->where('created_at', '<=', now()->subMinute())->pluck('id') as $docId) {
                        PrepareDocument::dispatch($docId);
                        $dispatched++;
                    }
                    foreach ((clone $base)->where('status', DocumentStatus::Submitted)->whereNotNull('lhdn_submission_uid')->where('submitted_at', '<=', now()->subMinutes(2))->distinct()->get(['issuer_id', 'lhdn_submission_uid']) as $row) {
                        PollSubmission::dispatch($row->issuer_id, (string) $row->lhdn_submission_uid);
                        $dispatched++;
                    }
                } finally {
                    $context->clear();
                }
            }
        }
        $this->info("Dispatched {$dispatched} job(s).");

        return self::SUCCESS;
    }
}
```
`routes/console.php`: add `use Illuminate\Support\Facades\Schedule; Schedule::command('einvoice:lhdn-dispatch')->everyMinute()->withoutOverlapping();`.

`DocumentData::fromModel` — in the `lhdn` block add `'validation_url' => ($d->lhdn_uuid && $d->lhdn_long_id) ? rtrim((string) config("lhdn.environments.{$d->environment->value}.portal_base"), '/')."/{$d->lhdn_uuid}/share/{$d->lhdn_long_id}" : null`.

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Feature/Lhdn/SubmissionPipelineTest.php tests/Feature/Documents tests/Unit/Tenancy` → pass (if the after-commit timing prevents the inline chain in the first test, switch that test to dispatch `PrepareDocument` explicitly after creating with `submit=false` + `SubmitDocument` action, and note it); `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(lhdn): submission pipeline (prepare, batch submit, poll), scheduler sweep, validation url"
```

---

### Task 8: Cancellation, rejection handling via API, resubmit, problem mapping

**Files:**
- Create: `app/Actions/Documents/CancelDocument.php`, `app/Data/Requests/Documents/CancelDocumentData.php`, `tests/Feature/Lhdn/CancelDocumentTest.php`
- Modify: `app/Http/Controllers/Api/V1/DocumentController.php` (`cancel`), `routes/api.php`, `tests/Feature/TenantIsolationSweepTest.php` (cancel rows), `tests/Feature/Documents/DocumentEndpointsTest.php` (resubmit from invalid)

**Interfaces:**
- Produces:
  - `CancelDocumentData(string $reason /*max 300*/)`.
  - `CancelDocument::handle(Document $document, string $reason): Document` — status must be `valid` (else 409 `invalid_transition`); window check via `document->isCancellable()` (else throw `CancellationWindowClosed` → 409 `cancellation_window_closed`); `clients->for($document->issuer)->cancelDocument($issuer, $document->lhdn_uuid, $reason)`; then `transition(Cancelled, $reason)`; audit `document.cancelled` (`{reason}`); `LhdnException` propagates (mapped 503/409/422).
  - Route `POST /documents/{document}/cancel` (`ability:documents:write`) → 200 `DocumentData`.
  - `POST /documents/{document}/submit` now also resubmits `invalid` documents (Task 1 made `SubmitDocument` accept invalid) — test it.

- [ ] **Step 1: Write failing tests `tests/Feature/Lhdn/CancelDocumentTest.php`**

```php
<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Lhdn\LhdnException;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;

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
    $old = Document::factory()->for($this->issuer)->valid()->create(['lhdn_status_at' => now()->subHours(80)]);
    app(TenantContext::class)->clear();
    $this->withHeaders($this->h)->postJson("/v1/documents/{$old->id}/cancel", ['reason' => 'late'])->assertStatus(409)->assertJsonPath('code', 'cancellation_window_closed');
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
    \Illuminate\Support\Facades\Queue::fake();
    $this->withHeaders($this->h)->postJson("/v1/documents/{$invalid->id}/submit")->assertOk()->assertJsonPath('data.status', 'queued');
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\PrepareDocument::class);
});
```
Sweep rows: `'document cancel' => [valid-document factory, 'POST', '/v1/documents/{id}/cancel']` (tenant + environment axis; body `['reason' => 'x']` is already included in the sweep's generic body — add `'reason' => 'x'` to that array).

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement**

`app/Data/Requests/Documents/CancelDocumentData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class CancelDocumentData extends Data
{
    public function __construct(#[Max(300)] public string $reason) {}
}
```

`app/Actions/Documents/CancelDocument.php`
```php
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
        $issuer = $document->issuer;
        $this->clients->for($issuer)->cancelDocument($issuer, (string) $document->lhdn_uuid, $reason);
        $this->sm->transition($document, DocumentStatus::Cancelled, $reason);
        $this->audit->record('document.cancelled', $document, ['reason' => $reason]);

        return $document->refresh();
    }
}
```

`DocumentController::cancel(CancelDocumentData $data, Document $document, CancelDocument $cancel): JsonResponse` → `response()->json(['data' => DocumentData::fromModel($cancel->handle($document, $data->reason))->toArray()])`. Route under `ability:documents:write`: `Route::post('/documents/{document}/cancel', [DocumentController::class, 'cancel']);`.

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Feature/Lhdn tests/Feature/Documents tests/Feature/TenantIsolationSweepTest.php` → pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(documents): cancel via LHDN within the 72h window; resubmit invalid documents"
```

---

### Task 9: Opt-in sandbox integration test, docs, spec amendments

**Files:**
- Create: `tests/Integration/LhdnSandboxTest.php`, `docs/lhdn-gateway.md`
- Modify: `phpunit.xml` (add `Integration` suite excluded by default… Pest runs `tests/` recursively — instead gate with `skip` unless env), `tests/Pest.php` (`pest()->extend(TestCase::class)->in('Integration')`), `README.md` (LHDN env vars + `einvoice:lhdn-dispatch` + queue worker), `CLAUDE.md` (nothing new unless needed), `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` (§6 decisions: `lhdn_internal_id`, poll/backoff config, breaker per environment), `.env.example` (already done in Task 2)

**Interfaces:**
- Produces: `tests/Integration/LhdnSandboxTest.php` — every test starts with `if (env('LHDN_SANDBOX_TESTS') !== '1') { $this->markTestSkipped('Set LHDN_SANDBOX_TESTS=1 with sandbox credentials to run.'); }`; uses `LHDN_DRIVER=http` forced via `config(['lhdn.driver' => 'http'])`, real sandbox base URLs from env, intermediary credentials from env; tests: (1) token fetch for an issuer with `tin = env('LHDN_SANDBOX_TEST_TIN')`; (2) `validateTin` for `env('LHDN_SANDBOX_TEST_TIN')`/`LHDN_SANDBOX_TEST_ID_TYPE`/`LHDN_SANDBOX_TEST_ID_VALUE` → true; (3) `getDocument` for a known invalid uuid → terminal 404 → `LhdnException`. No document submission in the automated suite (submissions create real sandbox records); a manual smoke command is out of scope.
- `docs/lhdn-gateway.md`: how the pipeline works (states, jobs, schedule, config knobs, error kinds, how to read `submission_attempts`), how to run the sandbox tests, how to onboard an issuer (verify-tin → authorize → certificate → active), and the `lhdn_internal_id` decision.

- [ ] **Step 1: Write the integration test**

```php
<?php

use App\Enums\Environment;
use App\Enums\IdType;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (env('LHDN_SANDBOX_TESTS') !== '1') {
        $this->markTestSkipped('Set LHDN_SANDBOX_TESTS=1 (and LHDN_SANDBOX_* credentials) to run LHDN sandbox tests.');
    }
    config(['lhdn.driver' => 'http']);
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create([
        'tin' => (string) env('LHDN_SANDBOX_TEST_TIN'),
        'id_type' => IdType::from((string) env('LHDN_SANDBOX_TEST_ID_TYPE', 'BRN')),
        'id_number' => (string) env('LHDN_SANDBOX_TEST_ID_VALUE'),
    ]);
});

it('fetches a sandbox token as intermediary', function () {
    $token = app(LhdnClientFactory::class)->for($this->issuer)->token($this->issuer);
    expect($token->token)->not->toBe('')->and($token->isExpired())->toBeFalse();
});

it('validates the test TIN', function () {
    expect(app(LhdnClientFactory::class)->for($this->issuer)->validateTin(Environment::Sandbox, $this->issuer->tin, $this->issuer->id_type->value, $this->issuer->id_number, $this->issuer))->toBeTrue();
});

it('classifies an unknown document as a terminal error', function () {
    expect(fn () => app(LhdnClientFactory::class)->for($this->issuer)->getDocument($this->issuer, '00000000000000000000000000'))->toThrow(LhdnException::class);
});
```
`tests/Pest.php`: add `pest()->extend(TestCase::class)->in('Integration');`.

- [ ] **Step 2: Docs** — write `docs/lhdn-gateway.md` (sections: Overview & states; Components; Configuration (`config/lhdn.php` keys + env vars); Running (queue worker `php artisan queue:work`, scheduler `php artisan schedule:work`, `einvoice:lhdn-dispatch`); Onboarding an issuer; Error handling & `submission_attempts`; Sandbox tests; Decisions). README: add "LHDN" section with env vars + the two processes. Spec §6 additions: "`codeNumber`/`Invoice.ID` = `documents.lhdn_internal_id` (document ULID)"; "Circuit breaker is per environment; rate budgets per issuer per operation; backoff arrays in `config/lhdn.php`"; §5.3 note `queued → invalid` and `held → held` (if not already added in Task 1).

- [ ] **Step 3: Run** — `composer check` (the Integration tests are skipped locally, shown as skipped — output stays pristine); commit.

```bash
git add -A
git commit -m "docs(lhdn): gateway guide, sandbox integration tests (opt-in), spec amendments"
```

---

## Plan self-review (done at authoring time)

- **Spec coverage:** §4.4 TIN validation endpoint + cache → Task 6; §6.1 clients (intermediary/own/fake) + factory → Tasks 2–3; §6.2 token cache with single-flight → Task 3; §6.3 pipeline (build → hash → sign → queue; batcher ≤100/5 MB; poller backoff; `submission_attempts`) → Tasks 1, 4, 5, 7; §6.4 per-issuer rate limits, transient retry/backoff → `held lhdn_unavailable`, terminal → invalid, circuit breaker per environment → Tasks 3, 7; §6.5 cancel within 72h, buyer rejection → `rejected` → Tasks 7–8; §8 rows `verify-tin`, `authorize`, `tin/validate`, `submit` (resubmit), `cancel` → Tasks 6, 8; §9 LHDN errors on documents (`lhdn_errors`) + problem mapping → Tasks 6–8; §10 opt-in sandbox tests → Task 9; Plan 2 backlog (typed `HeldReason`, `held→held`, `submission_attempts`, ubl/sign/pdf columns) → Task 1.
- **Placeholders:** none; the one "verify empirically" note (Task 7 Step 1 on after-commit timing) names the concrete fallback.
- **Type consistency:** `LhdnClient` method signatures identical across interface/Fake/Http (Tasks 2–3) and callers (Tasks 6–8); `DocumentStateMachine::transition(..., ?HeldReason $heldReason)` (Task 1) used by Tasks 7–8; `SubmissionDocument::fromJson(internalId, json)`/`SubmissionBatch` used by Task 7; `SignedDocument {document, json, hashHex}` used by Task 7; `TenantAwareJob::tenantMiddleware()` introduced in Task 7 (edit the trait there); `LhdnClientFactory::for()/forEnvironment()` used by Tasks 6–8; `FakeLhdnClient` scripting names used consistently in tests.
