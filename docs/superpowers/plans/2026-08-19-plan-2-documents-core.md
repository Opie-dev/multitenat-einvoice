# E-Invoice Engine — Plan 2: Documents Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the document core: tenant-aware queued jobs, document/line/event storage, the canonical `CreateDocumentData` DTO with engine-computed totals, the document state machine, idempotent create/batch/get/submit/events endpoints, and automatic release of held documents when an issuer activates — documents stop at `queued` (LHDN submission is Plan 3).

**Architecture:** Controllers stay thin: DTO in → `App\Actions\Documents\*` action → resource DTO out. Pure-PHP domain code in `app/Domain/Documents` (`TotalsCalculator`, `DocumentStateMachine`) has no HTTP/Eloquent-query dependencies beyond models passed in. Every status change goes through `DocumentStateMachine`, which writes `document_events` and dispatches `DocumentTransitioned`. Money is handled with `brick/math` `BigDecimal` (HALF_UP to 2 dp) and serialised as decimal strings. Queued jobs carry tenant + environment and re-bind `TenantContext` through job middleware.

**Tech Stack:** Laravel 12, PHP 8.3+, spatie/laravel-data v4, brick/math, Pest, Larastan level 8, SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` §3.1 (jobs), §5.1–5.5, §8 (documents rows), §9. Roadmap: `docs/superpowers/plans/2026-08-19-einvoice-engine-roadmap.md` (row 2 + "Plan 1 outcome" backlog).

## Global Constraints

- Everything in `CLAUDE.md` applies (tenancy, environments, DTO rule, problem+json, secrets, `composer check` green before every commit, conventional commits, no direct work on `master`).
- Documents are tenant-owned (`BelongsToTenant`) AND environment-scoped: `documents.environment` is copied from the issuer at creation; list/show/binding use `forCurrentEnvironment()` exactly like `Issuer`.
- One document = one issuer + one buyer. Batch fan-out is the caller's split; the engine only assigns a shared `group_id`.
- Status values (exact): `draft, validated, held, queued, submitted, valid, invalid, cancelled, rejected, awaiting_consolidation, consolidated`. Held reasons (exact): `issuer_not_active, certificate_expired, lhdn_credentials_invalid, lhdn_unavailable, einvoice_not_required`.
- Document types (exact, with LHDN codes): `invoice=01, credit_note=02, debit_note=03, refund_note=04, self_billed_invoice=11, self_billed_credit_note=12, self_billed_debit_note=13, self_billed_refund_note=14`.
- Money: amounts are `decimal(18,2)`, quantities/unit prices `decimal(18,4)`, rates `decimal(8,4)` (percent, e.g. `6.0000`), exchange rate `decimal(18,6)`. Arithmetic via `Brick\Math\BigDecimal`, rounding `HALF_UP` to 2 dp at line level; API emits money as decimal strings (`"12.00"`) and accepts numeric strings or numbers.
- Caller-supplied line `subtotal`/`tax_amount`/`total` and document `totals.total_payable` are optional; when present they must match the engine's value within `0.01` or the request is 422 with pointer to the field and code `totals_mismatch`.
- Idempotency: natural key unique `(tenant_id, source_system, source_ref, type)`; a repeat with the same `payload_hash` returns the existing document with `200` + header `Idempotent-Replay: true`; a different payload → `409 idempotency_conflict`. Optional `Idempotency-Key` header: cached response replay for 24h (`200`/`201` as originally returned + `Idempotent-Replay: true`); same key with a different request hash → `409 idempotency_key_reused`.
- Cancellation window: 72 hours from `lhdn_status_at`; outside → `409 cancellation_window_closed`.
- Every list uses deterministic cursor ordering `orderByDesc('created_at')->orderByDesc('id')`.
- New tenant-scoped routes get rows in `tests/Feature/TenantIsolationSweepTest.php` (tenant axis and environment axis).
- Abilities: reads `read`; writes `documents:write`.
- Windows/Git Bash: `vendor/bin/pest <path>` for focused runs; `composer check` before every commit.

---

## File structure (created/modified across the tasks)

```
app/
  Tenancy/Jobs/TenantAwareJob.php            trait: captures tenant+env at construct, exposes middleware()
  Tenancy/Jobs/BindTenantContext.php         job middleware: rebinds TenantContext, clears after
  Enums/DocumentType.php  DocumentStatus.php  HeldReason.php
  Domain/Documents/Money.php                 BigDecimal helpers (of, round2, str)
  Domain/Documents/TotalsCalculator.php      pure: lines → LineTotals[] + DocumentTotals; mismatch detection
  Domain/Documents/LineTotals.php  DocumentTotals.php  TotalsMismatch.php (exception)
  Domain/Documents/DocumentStateMachine.php  transitions map, transition(), cancellation window
  Domain/Documents/InvalidTransition.php  CancellationWindowClosed.php
  Events/DocumentTransitioned.php  Events/IssuerActivated.php
  Listeners/ReleaseHeldDocumentsOnActivation.php
  Jobs/ReleaseHeldDocuments.php              tenant-aware job
  Models/Document.php  DocumentLine.php  DocumentEvent.php
  Data/Requests/Documents/DocumentLineData.php  DocumentBuyerData.php  DocumentPaymentData.php
                         DocumentSourceData.php  OriginalDocumentRefData.php  DocumentTotalsInputData.php
                         CreateDocumentData.php  CreateDocumentBatchData.php
  Data/Resources/DocumentData.php  DocumentLineResourceData.php  DocumentTotalsData.php  DocumentEventData.php
  Actions/Documents/ResolveBuyerSnapshot.php  CreateDocument.php  CreateDocumentBatch.php  SubmitDocument.php
  Actions/Documents/DocumentSemanticValidator.php
  Http/Middleware/IdempotencyKey.php
  Http/Controllers/Api/V1/DocumentController.php  DocumentBatchController.php
  Services/Issuers/IssuerActivator.php       (modified: dispatch IssuerActivated)
database/migrations/2026_08_20_000001_create_documents_table.php  …000002_create_document_lines_table.php  …000003_create_document_events_table.php
database/factories/DocumentFactory.php  DocumentLineFactory.php
routes/api.php  bootstrap/app.php (alias `idempotency`)  config/einvoice.php (idempotency ttl)
tests/Unit/Tenancy/TenantAwareJobTest.php  tests/Unit/Documents/{MoneyTest,TotalsCalculatorTest,DocumentStateMachineTest,EnumsTest}.php
tests/Feature/Documents/{CreateDocumentTest,DocumentBatchTest,DocumentQueryTest,DocumentSubmitTest,IdempotencyKeyTest,HeldReleaseTest}.php
tests/Feature/TenantIsolationSweepTest.php (rows added)
```

Parallel waves for the executor: **{1 ∥ 2} → {3 ∥ 4 ∥ 5} → {6 ∥ 8} → 7 → 9 → 10**.

---

### Task 1: Tenant-aware queued jobs

**Files:**
- Create: `app/Tenancy/Jobs/TenantAwareJob.php`, `app/Tenancy/Jobs/BindTenantContext.php`, `tests/Unit/Tenancy/TenantAwareJobTest.php`
- Modify: `app/Auth/Actor.php` (no signature change; only a doc note that `type` may be `system`)

**Interfaces:**
- Consumes: `App\Tenancy\TenantContext::bind(?Tenant, ?Actor, Environment)`, `tenant()`, `environment()`, `clear()`; `App\Auth\Actor(type, id, name, abilities)`; `App\Models\Tenant`.
- Produces:
  - trait `App\Tenancy\Jobs\TenantAwareJob` — public props `string $tenantId`, `string $tenantEnvironment`; `protected function captureTenantContext(): void` (call in the job constructor); `public function middleware(): array` returning `[new BindTenantContext]`.
  - `App\Tenancy\Jobs\BindTenantContext::handle(object $job, Closure $next): mixed` — loads `Tenant::findOrFail($job->tenantId)`, binds `TenantContext` with actor `new Actor('system', $job::class, class_basename($job), ['*'])` and `Environment::from($job->tenantEnvironment)`, runs the job, always `clear()`s afterwards.

- [ ] **Step 1: Write the failing test `tests/Unit/Tenancy/TenantAwareJobTest.php`**

```php
<?php

use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\Jobs\TenantAwareJob;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

class RecordingTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels, TenantAwareJob;

    /** @var array<int, array{tenant: ?string, env: string, actor: ?string}> */
    public static array $seen = [];

    public function __construct()
    {
        $this->captureTenantContext();
    }

    public function handle(TenantContext $context): void
    {
        self::$seen[] = [
            'tenant' => $context->tenantOrNull()?->id,
            'env' => $context->environment()->value,
            'actor' => $context->actor()?->label(),
        ];
    }
}

beforeEach(fn () => RecordingTenantJob::$seen = []);

it('captures tenant and environment at construction and rebinds them when handled', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->bind($tenant, null, Environment::Sandbox);

    $job = new RecordingTenantJob;
    expect($job->tenantId)->toBe($tenant->id)->and($job->tenantEnvironment)->toBe('sandbox');

    $context->clear();
    dispatch_sync($job);

    expect(RecordingTenantJob::$seen)->toHaveCount(1)
        ->and(RecordingTenantJob::$seen[0]['tenant'])->toBe($tenant->id)
        ->and(RecordingTenantJob::$seen[0]['env'])->toBe('sandbox')
        ->and(RecordingTenantJob::$seen[0]['actor'])->toBe('system:RecordingTenantJob');
});

it('clears the context after the job finishes', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->bind($tenant, null, Environment::Production);
    $job = new RecordingTenantJob;
    $context->clear();

    dispatch_sync($job);

    expect($context->has())->toBeFalse();
});

it('throws when constructed without a tenant context', function () {
    app(TenantContext::class)->clear();
    new RecordingTenantJob;
})->throws(\App\Tenancy\Exceptions\NoTenantContext::class);

it('serialises only scalar tenant data (queue-safe)', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    Bus::fake();
    RecordingTenantJob::dispatch();
    Bus::assertDispatched(RecordingTenantJob::class, fn (RecordingTenantJob $j) => $j->tenantId === $tenant->id && $j->tenantEnvironment === 'sandbox');
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Tenancy/TenantAwareJobTest.php` → FAIL (trait not found).

- [ ] **Step 3: Implement**

`app/Tenancy/Jobs/TenantAwareJob.php`
```php
<?php

namespace App\Tenancy\Jobs;

use App\Tenancy\TenantContext;

/**
 * Give a queued job the tenant + environment it was dispatched under, and
 * re-bind them (via BindTenantContext middleware) when the job runs.
 * Call captureTenantContext() in the job constructor.
 */
trait TenantAwareJob
{
    public string $tenantId;

    public string $tenantEnvironment;

    protected function captureTenantContext(): void
    {
        $context = app(TenantContext::class);
        $this->tenantId = $context->tenant()->getKey(); // throws NoTenantContext when unbound
        $this->tenantEnvironment = $context->environment()->value;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new BindTenantContext];
    }
}
```

`app/Tenancy/Jobs/BindTenantContext.php`
```php
<?php

namespace App\Tenancy\Jobs;

use App\Auth\Actor;
use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;

class BindTenantContext
{
    /**
     * @param  object&\App\Tenancy\Jobs\TenantAwareJob  $job
     */
    public function handle(object $job, Closure $next): mixed
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->findOrFail($job->tenantId);
        $context->bind(
            $tenant,
            new Actor('system', $job::class, class_basename($job), ['*']),
            Environment::from($job->tenantEnvironment),
        );

        try {
            return $next($job);
        } finally {
            $context->clear();
        }
    }
}
```
Add to `app/Auth/Actor.php` class docblock: `@phpstan-type ActorType 'service'|'api_key'|'system'` and a one-line comment that `system` is used by tenant-aware jobs.

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Unit/Tenancy` → all pass. Then `composer check` (green).

- [ ] **Step 5: Commit**

```bash
git add app/Tenancy/Jobs app/Auth/Actor.php tests/Unit/Tenancy/TenantAwareJobTest.php
git commit -m "feat(tenancy): tenant-aware queued jobs with TenantContext re-binding middleware"
```

---

### Task 2: Document enums and Money helpers (brick/math)

**Files:**
- Create: `app/Enums/DocumentType.php`, `app/Enums/DocumentStatus.php`, `app/Enums/HeldReason.php`, `app/Domain/Documents/Money.php`, `tests/Unit/Documents/EnumsTest.php`, `tests/Unit/Documents/MoneyTest.php`
- Modify: `composer.json` (require `brick/math`)

**Interfaces:**
- Produces:
  - `DocumentType` (backed strings above) with `lhdnCode(): string`, `isSelfBilled(): bool`, `requiresOriginalRef(): bool` (true for all *_note types), `static fromLhdnCode(string): self`.
  - `DocumentStatus` (backed strings above) with `isTerminal(): bool` (`cancelled, rejected, consolidated`), `isHeld(): bool`.
  - `HeldReason` (backed strings above) with `releasableOnIssuerActivation(): bool` (true for `issuer_not_active`, `certificate_expired`).
  - `Money::of(int|float|string $v): BigDecimal`, `Money::round2(BigDecimal): BigDecimal` (HALF_UP, scale 2), `Money::str(BigDecimal, int $scale = 2): string` (fixed-scale string), `Money::withinTolerance(BigDecimal $a, BigDecimal $b, string $tol = '0.01'): bool`.

- [ ] **Step 1: Install brick/math**

Run: `composer require brick/math --no-interaction` (production dependency). Verify `composer.json` gained `"brick/math": "^0.13"` (or the current 0.x caret).

- [ ] **Step 2: Write failing tests**

`tests/Unit/Documents/EnumsTest.php`
```php
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
```

`tests/Unit/Documents/MoneyTest.php`
```php
<?php

use App\Domain\Documents\Money;
use Brick\Math\BigDecimal;

it('parses ints, floats and numeric strings without float drift', function () {
    expect(Money::of('0.1')->plus(Money::of('0.2'))->isEqualTo(BigDecimal::of('0.3')))->toBeTrue()
        ->and(Money::of(3)->toScale(2)->__toString())->toBe('3.00')
        ->and(Money::of(19.99)->__toString())->toBe('19.99');
});

it('rounds half-up to 2 dp and formats fixed scale', function () {
    expect(Money::str(Money::round2(Money::of('2.345'))))->toBe('2.35')
        ->and(Money::str(Money::round2(Money::of('2.344'))))->toBe('2.34')
        ->and(Money::str(Money::of('7'), 4))->toBe('7.0000');
});

it('compares within tolerance', function () {
    expect(Money::withinTolerance(Money::of('10.00'), Money::of('10.01')))->toBeTrue()
        ->and(Money::withinTolerance(Money::of('10.00'), Money::of('10.02')))->toBeFalse();
});
```

- [ ] **Step 3: Run to verify failure** — `vendor/bin/pest tests/Unit/Documents` → FAIL.

- [ ] **Step 4: Implement**

`app/Enums/DocumentType.php`
```php
<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case RefundNote = 'refund_note';
    case SelfBilledInvoice = 'self_billed_invoice';
    case SelfBilledCreditNote = 'self_billed_credit_note';
    case SelfBilledDebitNote = 'self_billed_debit_note';
    case SelfBilledRefundNote = 'self_billed_refund_note';

    public function lhdnCode(): string
    {
        return match ($this) {
            self::Invoice => '01',
            self::CreditNote => '02',
            self::DebitNote => '03',
            self::RefundNote => '04',
            self::SelfBilledInvoice => '11',
            self::SelfBilledCreditNote => '12',
            self::SelfBilledDebitNote => '13',
            self::SelfBilledRefundNote => '14',
        };
    }

    public static function fromLhdnCode(string $code): self
    {
        foreach (self::cases() as $case) {
            if ($case->lhdnCode() === $code) {
                return $case;
            }
        }
        throw new \ValueError("Unknown LHDN document type code {$code}");
    }

    public function isSelfBilled(): bool
    {
        return str_starts_with($this->value, 'self_billed_');
    }

    public function requiresOriginalRef(): bool
    {
        return str_ends_with($this->value, '_note');
    }
}
```

`app/Enums/DocumentStatus.php`
```php
<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case Held = 'held';
    case Queued = 'queued';
    case Submitted = 'submitted';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Cancelled = 'cancelled';
    case Rejected = 'rejected';
    case AwaitingConsolidation = 'awaiting_consolidation';
    case Consolidated = 'consolidated';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Cancelled, self::Rejected, self::Consolidated], true);
    }

    public function isHeld(): bool
    {
        return $this === self::Held;
    }
}
```

`app/Enums/HeldReason.php`
```php
<?php

namespace App\Enums;

enum HeldReason: string
{
    case IssuerNotActive = 'issuer_not_active';
    case CertificateExpired = 'certificate_expired';
    case LhdnCredentialsInvalid = 'lhdn_credentials_invalid';
    case LhdnUnavailable = 'lhdn_unavailable';
    case EinvoiceNotRequired = 'einvoice_not_required';

    public function releasableOnIssuerActivation(): bool
    {
        return in_array($this, [self::IssuerNotActive, self::CertificateExpired], true);
    }
}
```

`app/Domain/Documents/Money.php`
```php
<?php

namespace App\Domain\Documents;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class Money
{
    public static function of(int|float|string $value): BigDecimal
    {
        // Floats go through a fixed-precision string to avoid binary drift (19.99 -> "19.99").
        return BigDecimal::of(is_float($value) ? number_format($value, 6, '.', '') : (string) $value)->stripTrailingZeros();
    }

    public static function round2(BigDecimal $value): BigDecimal
    {
        return $value->toScale(2, RoundingMode::HALF_UP);
    }

    public static function str(BigDecimal $value, int $scale = 2): string
    {
        return (string) $value->toScale($scale, RoundingMode::HALF_UP);
    }

    public static function withinTolerance(BigDecimal $a, BigDecimal $b, string $tolerance = '0.01'): bool
    {
        return $a->minus($b)->abs()->isLessThanOrEqualTo(BigDecimal::of($tolerance));
    }
}
```
Note: `Money::of(19.99)->__toString()` must give `19.99` — `stripTrailingZeros()` on `19.990000` yields `19.99`; on `3` yields `3` and the test scales it to `3.00`. `BigDecimal::of('0.1')` has no float involved.

- [ ] **Step 5: Run tests** — `vendor/bin/pest tests/Unit/Documents` → all pass; `composer check` green.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock app/Enums/DocumentType.php app/Enums/DocumentStatus.php app/Enums/HeldReason.php app/Domain/Documents/Money.php tests/Unit/Documents
git commit -m "feat(documents): document enums and brick/math money helpers"
```

---
### Task 3: Request DTOs — `CreateDocumentData` and its parts

**Files:**
- Create: `app/Data/Requests/Documents/DocumentLineData.php`, `DocumentBuyerData.php`, `DocumentPaymentData.php`, `DocumentSourceData.php`, `OriginalDocumentRefData.php`, `DocumentTotalsInputData.php`, `CreateDocumentData.php`, `CreateDocumentBatchData.php`, `tests/Unit/Documents/CreateDocumentDataTest.php`

**Interfaces:**
- Consumes: `App\Enums\{DocumentType, IdType}`.
- Produces (all `Spatie\LaravelData\Data`, snake_case props = JSON keys):
  - `DocumentLineData(string $classification_code /*3 digits*/, string $description /*max 300*/, int|float|string $quantity /*numeric > 0*/, string $unit_code /*max 10*/, int|float|string $unit_price /*numeric >= 0*/, string $tax_type /*in 01..06,E*/, int|float|string|null $tax_rate = null /*0..100*/, int|float|string|null $tax_amount = null, int|float|string|null $discount_amount = null, int|float|string|null $discount_rate = null /*0..100*/, ?string $tax_exemption_reason = null /*max 300, required_if tax_type=E*/, int|float|string|null $subtotal = null, int|float|string|null $total = null, ?array $metadata = null)`
  - `DocumentBuyerData(?string $buyer_id = null, bool $general_public = false, ?string $name = null, ?string $tin = null, ?IdType $id_type = null, ?string $id_number = null, ?string $sst_number = null, ?string $email = null, ?string $phone = null, ?string $address_line1 = null, ?string $address_line2 = null, ?string $address_line3 = null, ?string $postcode = null, ?string $city = null, ?string $state_code = null, ?string $country_code = null)` with method `mode(): 'buyer_id'|'general_public'|'inline'|'invalid'` — exactly one of `buyer_id`, `general_public=true`, or inline (`name` present) must be used.
  - `DocumentPaymentData(?string $mode = null /*ref payment_modes code, 2 chars*/, ?string $terms = null /*max 300*/, ?string $paid_at = null /*date*/, ?string $payment_ref = null /*max 150*/)`
  - `DocumentSourceData(string $system /*max 50*/, string $ref /*max 191*/)`
  - `OriginalDocumentRefData(?string $document_id = null, ?string $lhdn_uuid = null)` — exactly one required (`rules()` override with `required_without`).
  - `DocumentTotalsInputData(int|float|string|null $total_payable = null)`
  - `CreateDocumentData(DocumentType $type, string $issuer_id, DocumentBuyerData $buyer, DataCollection<int, DocumentLineData> $lines /*min 1, max 500*/, DocumentSourceData $source, string $currency = 'MYR' /*size 3 uppercase*/, int|float|string|null $exchange_rate = null, ?string $issue_date = null /*Y-m-d*/, ?OriginalDocumentRefData $original_document_ref = null, ?DocumentPaymentData $payment = null, ?DocumentTotalsInputData $totals = null, bool $consolidate = false, bool $submit = true, ?array $metadata = null /*json ≤ 8 KB enforced in Task 7*/)`, plus `payloadHash(): string` = `hash('sha256', json_encode(self::canonical($this->toArray())))` where `canonical()` recursively `ksort`s associative arrays and drops the `submit` key.
  - `CreateDocumentBatchData(DataCollection<int, CreateDocumentData> $documents /*min 1, max 100*/)`.

- [ ] **Step 1: Write failing tests `tests/Unit/Documents/CreateDocumentDataTest.php`**

```php
<?php

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
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Documents/CreateDocumentDataTest.php` → FAIL.

- [ ] **Step 3: Implement the DTOs**

`app/Data/Requests/Documents/DocumentLineData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentLineData extends Data
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        #[Regex('/^\d{3}$/')] public string $classification_code,
        #[Max(300)] public string $description,
        public int|float|string $quantity,
        #[Max(10)] public string $unit_code,
        public int|float|string $unit_price,
        #[Regex('/^(0[1-6]|E)$/')] public string $tax_type,
        public int|float|string|null $tax_rate = null,
        public int|float|string|null $tax_amount = null,
        public int|float|string|null $discount_amount = null,
        public int|float|string|null $discount_rate = null,
        #[Max(300)] public ?string $tax_exemption_reason = null,
        public int|float|string|null $subtotal = null,
        public int|float|string|null $total = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Numeric bounds + the conditional exemption reason. Defaulted properties are
     * skipped by the inferrer when absent, so every conditional rule is stated here.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['required', 'numeric', 'gte:0'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_amount' => ['nullable', 'numeric', 'gte:0'],
            'discount_amount' => ['nullable', 'numeric', 'gte:0'],
            'discount_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_exemption_reason' => ['nullable', 'string', 'max:300', 'required_if:tax_type,E'],
            'subtotal' => ['nullable', 'numeric'],
            'total' => ['nullable', 'numeric'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
```

`app/Data/Requests/Documents/DocumentBuyerData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use App\Enums\IdType;
use Illuminate\Validation\Rules\Enum;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentBuyerData extends Data
{
    public function __construct(
        #[Max(26)] public ?string $buyer_id = null,
        public bool $general_public = false,
        #[Max(300)] public ?string $name = null,
        #[Max(20)] public ?string $tin = null,
        public ?IdType $id_type = null,
        #[Max(30)] public ?string $id_number = null,
        #[Max(40)] public ?string $sst_number = null,
        #[Email, Max(320)] public ?string $email = null,
        #[Max(20)] public ?string $phone = null,
        #[Max(150)] public ?string $address_line1 = null,
        #[Max(150)] public ?string $address_line2 = null,
        #[Max(150)] public ?string $address_line3 = null,
        #[Max(10)] public ?string $postcode = null,
        #[Max(50)] public ?string $city = null,
        #[Size(2)] public ?string $state_code = null,
        #[Size(3)] public ?string $country_code = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'id_type' => ['nullable', new Enum(IdType::class), 'required_with:id_number'],
            'id_number' => ['nullable', 'string', 'max:30', 'required_with:id_type'],
        ];
    }

    /** @return 'buyer_id'|'general_public'|'inline'|'invalid' */
    public function mode(): string
    {
        $modes = array_filter([
            'buyer_id' => $this->buyer_id !== null && $this->buyer_id !== '',
            'general_public' => $this->general_public,
            'inline' => $this->name !== null && $this->name !== '',
        ]);
        if (count($modes) !== 1) {
            return 'invalid';
        }

        return (string) array_key_first($modes);
    }
}
```

`app/Data/Requests/Documents/DocumentPaymentData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

class DocumentPaymentData extends Data
{
    public function __construct(
        #[Regex('/^0[1-8]$/')] public ?string $mode = null,
        #[Max(300)] public ?string $terms = null,
        #[Date] public ?string $paid_at = null,
        #[Max(150)] public ?string $payment_ref = null,
    ) {}
}
```

`app/Data/Requests/Documents/DocumentSourceData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class DocumentSourceData extends Data
{
    public function __construct(
        #[Max(50)] public string $system,
        #[Max(191)] public string $ref,
    ) {}
}
```

`app/Data/Requests/Documents/OriginalDocumentRefData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class OriginalDocumentRefData extends Data
{
    public function __construct(
        public ?string $document_id = null,
        public ?string $lhdn_uuid = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'document_id' => ['nullable', 'string', 'max:26', 'required_without:lhdn_uuid', 'prohibits:lhdn_uuid'],
            'lhdn_uuid' => ['nullable', 'string', 'max:64', 'required_without:document_id'],
        ];
    }
}
```

`app/Data/Requests/Documents/DocumentTotalsInputData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentTotalsInputData extends Data
{
    public function __construct(public int|float|string|null $total_payable = null) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return ['total_payable' => ['nullable', 'numeric']];
    }
}
```

`app/Data/Requests/Documents/CreateDocumentData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use App\Enums\DocumentType;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateDocumentData extends Data
{
    /**
     * @param  DataCollection<int, DocumentLineData>  $lines
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public DocumentType $type,
        #[Max(26)] public string $issuer_id,
        public DocumentBuyerData $buyer,
        #[DataCollectionOf(DocumentLineData::class)] public DataCollection $lines,
        public DocumentSourceData $source,
        public string $currency = 'MYR',
        public int|float|string|null $exchange_rate = null,
        public ?string $issue_date = null,
        public ?OriginalDocumentRefData $original_document_ref = null,
        public ?DocumentPaymentData $payment = null,
        public ?DocumentTotalsInputData $totals = null,
        public bool $consolidate = false,
        public bool $submit = true,
        public ?array $metadata = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'currency' => ['string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'required_unless:currency,MYR'],
            'issue_date' => ['nullable', 'date_format:Y-m-d'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function payloadHash(): string
    {
        $array = $this->toArray();
        unset($array['submit']);

        return hash('sha256', (string) json_encode(self::canonical($array), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        if ($isAssoc) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonical($v);
            } elseif (is_int($v) || is_float($v)) {
                $value[$k] = (string) $v; // "2" and 2 hash the same
            }
        }

        return $value;
    }
}
```

`app/Data/Requests/Documents/CreateDocumentBatchData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class CreateDocumentBatchData extends Data
{
    /** @param DataCollection<int, CreateDocumentData> $documents */
    public function __construct(
        #[DataCollectionOf(CreateDocumentData::class)] public DataCollection $documents,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return ['documents' => ['required', 'array', 'min:1', 'max:100']];
    }
}
```

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Unit/Documents/CreateDocumentDataTest.php` → all pass. If spatie reports nested-collection rule keys differently (e.g. `lines.0.classification_code` is present but named `lines.*.…`), adjust the assertions to the actual key names — the *behaviour* (422 for those fields) is the requirement; note any change in the report. `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Data/Requests/Documents tests/Unit/Documents/CreateDocumentDataTest.php
git commit -m "feat(documents): CreateDocumentData request DTOs with validation and canonical payload hash"
```

---

### Task 4: `TotalsCalculator` (pure domain)

**Files:**
- Create: `app/Domain/Documents/LineTotals.php`, `app/Domain/Documents/DocumentTotals.php`, `app/Domain/Documents/TotalsMismatch.php`, `app/Domain/Documents/TotalsCalculator.php`, `tests/Unit/Documents/TotalsCalculatorTest.php`

**Interfaces:**
- Consumes: `App\Domain\Documents\Money`, `App\Data\Requests\Documents\{DocumentLineData, DocumentTotalsInputData}`.
- Produces:
  - `final class LineTotals { public function __construct(public readonly BigDecimal $quantity, public readonly BigDecimal $unitPrice, public readonly BigDecimal $gross /*qty×price, 2dp*/, public readonly BigDecimal $discount /*2dp*/, public readonly BigDecimal $subtotal /*gross−discount*/, public readonly ?BigDecimal $taxRate, public readonly BigDecimal $taxAmount, public readonly BigDecimal $total /*subtotal+tax*/) {} }`
  - `final class DocumentTotals { public function __construct(public readonly BigDecimal $subtotal /*Σgross*/, public readonly BigDecimal $discountTotal, public readonly BigDecimal $totalExcludingTax, public readonly BigDecimal $taxTotal, public readonly BigDecimal $totalIncludingTax, public readonly BigDecimal $totalPayable, /** @var list<LineTotals> */ public readonly array $lines) {} public function toStrings(): array /* keys subtotal, discount_total, total_excluding_tax, tax_total, total_including_tax, total_payable → "12.00" */ }`
  - `final class TotalsMismatch extends \RuntimeException { public function __construct(public readonly string $pointer /* e.g. "/lines/0/total" */, public readonly string $expected, public readonly string $given) }`
  - `TotalsCalculator::calculate(iterable<int, DocumentLineData> $lines, ?DocumentTotalsInputData $totals = null): DocumentTotals` — computes; throws `TotalsMismatch` for the first caller-supplied figure outside `0.01` tolerance (`/lines/{i}/subtotal`, `/lines/{i}/tax_amount`, `/lines/{i}/total`, `/totals/total_payable`).
  - Rules: `gross = round2(qty × price)`; `discount = round2(discount_amount ?? gross × discount_rate/100 ?? 0)`; `subtotal = gross − discount`; `tax = round2(tax_amount ?? subtotal × tax_rate/100 ?? 0)` — when BOTH `tax_amount` and `tax_rate` are supplied, the supplied amount wins but must match the computed within tolerance; `total = subtotal + tax`.

- [ ] **Step 1: Write failing tests `tests/Unit/Documents/TotalsCalculatorTest.php`**

```php
<?php

use App\Data\Requests\Documents\DocumentLineData;
use App\Data\Requests\Documents\DocumentTotalsInputData;
use App\Domain\Documents\TotalsCalculator;
use App\Domain\Documents\TotalsMismatch;

function line(array $o = []): DocumentLineData
{
    return DocumentLineData::from(array_replace([
        'classification_code' => '022', 'description' => 'x', 'quantity' => 1, 'unit_code' => 'C62',
        'unit_price' => '10.00', 'tax_type' => '02',
    ], $o));
}

it('computes line and document totals with half-up rounding', function () {
    $totals = (new TotalsCalculator)->calculate([
        line(['quantity' => 3, 'unit_price' => '3.333', 'tax_rate' => 6]),      // gross 10.00 (9.999→10.00), tax 0.60
        line(['quantity' => 1, 'unit_price' => '100', 'discount_rate' => 10, 'tax_rate' => 8]), // gross 100, disc 10, sub 90, tax 7.20
        line(['quantity' => 2, 'unit_price' => '5', 'tax_type' => '06']),        // no tax
    ]);
    $s = $totals->toStrings();
    expect($s)->toBe([
        'subtotal' => '120.00', 'discount_total' => '10.00', 'total_excluding_tax' => '110.00',
        'tax_total' => '7.80', 'total_including_tax' => '117.80', 'total_payable' => '117.80',
    ]);
    expect((string) $totals->lines[0]->gross)->toBe('10.00')->and((string) $totals->lines[1]->total)->toBe('97.20');
});

it('accepts caller figures within tolerance and rejects mismatches with a pointer', function () {
    $ok = (new TotalsCalculator)->calculate([line(['quantity' => 3, 'unit_price' => '3.333', 'tax_rate' => 6, 'subtotal' => '9.99', 'total' => '10.60'])]);
    expect($ok->toStrings()['total_payable'])->toBe('10.60');

    try {
        (new TotalsCalculator)->calculate([line(['quantity' => 1, 'unit_price' => '10', 'total' => '12.00'])]);
        $this->fail('expected mismatch');
    } catch (TotalsMismatch $e) {
        expect($e->pointer)->toBe('/lines/0/total')->and($e->expected)->toBe('10.00')->and($e->given)->toBe('12.00');
    }
});

it('prefers a supplied tax_amount but checks it against the rate', function () {
    $t = (new TotalsCalculator)->calculate([line(['quantity' => 1, 'unit_price' => '100', 'tax_rate' => 6, 'tax_amount' => '6.01'])]);
    expect((string) $t->lines[0]->taxAmount)->toBe('6.01');
    expect(fn () => (new TotalsCalculator)->calculate([line(['quantity' => 1, 'unit_price' => '100', 'tax_rate' => 6, 'tax_amount' => '9.00'])]))
        ->toThrow(TotalsMismatch::class);
});

it('validates document-level total_payable', function () {
    expect(fn () => (new TotalsCalculator)->calculate([line()], new DocumentTotalsInputData(total_payable: '99')))
        ->toThrow(TotalsMismatch::class);
    $ok = (new TotalsCalculator)->calculate([line()], new DocumentTotalsInputData(total_payable: '10.00'));
    expect($ok->toStrings()['total_payable'])->toBe('10.00');
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Documents/TotalsCalculatorTest.php` → FAIL.

- [ ] **Step 3: Implement**

`app/Domain/Documents/TotalsMismatch.php`
```php
<?php

namespace App\Domain\Documents;

use RuntimeException;

final class TotalsMismatch extends RuntimeException
{
    public function __construct(public readonly string $pointer, public readonly string $expected, public readonly string $given)
    {
        parent::__construct("Value at {$pointer} was {$given}, expected {$expected}.");
    }
}
```

`app/Domain/Documents/LineTotals.php`
```php
<?php

namespace App\Domain\Documents;

use Brick\Math\BigDecimal;

final class LineTotals
{
    public function __construct(
        public readonly BigDecimal $quantity,
        public readonly BigDecimal $unitPrice,
        public readonly BigDecimal $gross,
        public readonly BigDecimal $discount,
        public readonly BigDecimal $subtotal,
        public readonly ?BigDecimal $taxRate,
        public readonly BigDecimal $taxAmount,
        public readonly BigDecimal $total,
    ) {}
}
```

`app/Domain/Documents/DocumentTotals.php`
```php
<?php

namespace App\Domain\Documents;

use Brick\Math\BigDecimal;

final class DocumentTotals
{
    /** @param list<LineTotals> $lines */
    public function __construct(
        public readonly BigDecimal $subtotal,
        public readonly BigDecimal $discountTotal,
        public readonly BigDecimal $totalExcludingTax,
        public readonly BigDecimal $taxTotal,
        public readonly BigDecimal $totalIncludingTax,
        public readonly BigDecimal $totalPayable,
        public readonly array $lines,
    ) {}

    /** @return array{subtotal: string, discount_total: string, total_excluding_tax: string, tax_total: string, total_including_tax: string, total_payable: string} */
    public function toStrings(): array
    {
        return [
            'subtotal' => Money::str($this->subtotal),
            'discount_total' => Money::str($this->discountTotal),
            'total_excluding_tax' => Money::str($this->totalExcludingTax),
            'tax_total' => Money::str($this->taxTotal),
            'total_including_tax' => Money::str($this->totalIncludingTax),
            'total_payable' => Money::str($this->totalPayable),
        ];
    }
}
```

`app/Domain/Documents/TotalsCalculator.php`
```php
<?php

namespace App\Domain\Documents;

use App\Data\Requests\Documents\DocumentLineData;
use App\Data\Requests\Documents\DocumentTotalsInputData;
use Brick\Math\BigDecimal;

class TotalsCalculator
{
    /** @param iterable<int, DocumentLineData> $lines */
    public function calculate(iterable $lines, ?DocumentTotalsInputData $totals = null): DocumentTotals
    {
        $zero = BigDecimal::zero();
        $subtotal = $zero; $discountTotal = $zero; $taxTotal = $zero;
        $lineTotals = [];
        $i = 0;
        foreach ($lines as $line) {
            $lt = $this->line($line, $i);
            $lineTotals[] = $lt;
            $subtotal = $subtotal->plus($lt->gross);
            $discountTotal = $discountTotal->plus($lt->discount);
            $taxTotal = $taxTotal->plus($lt->taxAmount);
            $i++;
        }
        $excl = $subtotal->minus($discountTotal);
        $incl = $excl->plus($taxTotal);
        $payable = $incl;
        if ($totals?->total_payable !== null) {
            $this->assertClose('/totals/total_payable', $payable, Money::of($totals->total_payable));
        }

        return new DocumentTotals(
            Money::round2($subtotal), Money::round2($discountTotal), Money::round2($excl),
            Money::round2($taxTotal), Money::round2($incl), Money::round2($payable), $lineTotals,
        );
    }

    private function line(DocumentLineData $line, int $index): LineTotals
    {
        $qty = Money::of($line->quantity);
        $price = Money::of($line->unit_price);
        $gross = Money::round2($qty->multipliedBy($price));

        $discount = BigDecimal::zero();
        if ($line->discount_amount !== null) {
            $discount = Money::round2(Money::of($line->discount_amount));
        } elseif ($line->discount_rate !== null) {
            $discount = Money::round2($gross->multipliedBy(Money::of($line->discount_rate))->dividedBy(100, 6));
        }
        $sub = $gross->minus($discount);
        if ($line->subtotal !== null) {
            $this->assertClose("/lines/{$index}/subtotal", $sub, Money::of($line->subtotal));
        }

        $rate = $line->tax_rate !== null ? Money::of($line->tax_rate) : null;
        $computedTax = $rate !== null ? Money::round2($sub->multipliedBy($rate)->dividedBy(100, 6)) : BigDecimal::zero();
        $tax = $computedTax;
        if ($line->tax_amount !== null) {
            $given = Money::round2(Money::of($line->tax_amount));
            if ($rate !== null) {
                $this->assertClose("/lines/{$index}/tax_amount", $computedTax, $given);
            }
            $tax = $given;
        }
        $total = $sub->plus($tax);
        if ($line->total !== null) {
            $this->assertClose("/lines/{$index}/total", $total, Money::of($line->total));
        }

        return new LineTotals($qty, $price, $gross, $discount, Money::round2($sub), $rate, $tax, Money::round2($total));
    }

    private function assertClose(string $pointer, BigDecimal $expected, BigDecimal $given): void
    {
        if (! Money::withinTolerance($expected, $given)) {
            throw new TotalsMismatch($pointer, Money::str($expected), Money::str($given));
        }
    }
}
```
(`dividedBy(100, 6)` = scale 6 with brick's default rounding `UNNECESSARY` → use `dividedBy(100, 6, RoundingMode::HALF_UP)`; import `Brick\Math\RoundingMode`.)

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Unit/Documents/TotalsCalculatorTest.php` → pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Documents tests/Unit/Documents/TotalsCalculatorTest.php
git commit -m "feat(documents): TotalsCalculator with tolerance checks and pointered mismatches"
```

---

### Task 5: Storage — migrations, models, factories

**Files:**
- Create: `database/migrations/2026_08_20_000001_create_documents_table.php`, `…000002_create_document_lines_table.php`, `…000003_create_document_events_table.php`, `app/Models/Document.php`, `app/Models/DocumentLine.php`, `app/Models/DocumentEvent.php`, `database/factories/DocumentFactory.php`, `database/factories/DocumentLineFactory.php`, `tests/Unit/Documents/DocumentModelTest.php`

**Interfaces:**
- Consumes: enums from Task 2; `BelongsToTenant`; `Issuer`, `Buyer`; `TenantContext`.
- Produces:
  - `Document` (BelongsToTenant, HasUlids, `$guarded=['id','tenant_id']`): columns `issuer_id, buyer_id?, group_id?, environment, type (DocumentType), status (DocumentStatus), held_reason? (HeldReason), buyer_snapshot json, currency, exchange_rate?, issue_date (date), subtotal, discount_total, total_excluding_tax, tax_total, total_including_tax, total_payable (decimal strings), consolidate bool, source_system, source_ref, original_document_id?, original_lhdn_uuid?, payment json?, metadata json?, payload_hash, lhdn_uuid?, lhdn_long_id?, lhdn_submission_uid?, lhdn_errors json?, validated_at?, submitted_at?, lhdn_status_at?, cancelled_at?, cancel_reason?, consolidated_into_id?`; relations `issuer()`, `buyer()`, `lines()` (ordered by position), `events()` (ordered by created_at, id), `originalDocument()`; scopes `forCurrentEnvironment()`, `resolveRouteBinding` (env-scoped like Issuer); `isCancellable(): bool` (status valid && lhdn_status_at within 72h).
  - `DocumentLine` (BelongsToTenant): `document_id, position, classification_code, description, quantity, unit_code, unit_price, discount_amount, discount_rate?, tax_type, tax_rate?, tax_amount, tax_exemption_reason?, subtotal, total, metadata?`.
  - `DocumentEvent` (BelongsToTenant, `UPDATED_AT = null`): `document_id, from_status?, to_status, reason?, meta json?, actor_type?, actor_id?, created_at`.
  - Unique `(tenant_id, source_system, source_ref, type)`; indexes `(tenant_id, environment, status)`, `(tenant_id, issuer_id)`, `(tenant_id, group_id)`, `(document_id, position)`, `(document_id, created_at)`.
  - Factories: `DocumentFactory` (default `status` draft, `environment` sandbox, MYR, general-public snapshot, source `test/…`, totals zeros) with states `queued()`, `held(HeldReason)`, `valid()` (sets `lhdn_uuid`, `lhdn_status_at = now()`), and `for($issuer)`; `DocumentLineFactory`.

- [ ] **Step 1: Write failing test `tests/Unit/Documents/DocumentModelTest.php`**

```php
<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores decimals as strings, casts enums, and scopes by tenant + environment', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer = Issuer::factory()->for($tenant)->create();
    $doc = Document::factory()->for($issuer)->create(['total_payable' => '12.50', 'status' => DocumentStatus::Queued]);
    DocumentLine::factory()->for($doc)->create(['position' => 1]);

    $fresh = Document::forCurrentEnvironment()->with('lines')->find($doc->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->total_payable)->toBe('12.50')
        ->and($fresh->status)->toBe(DocumentStatus::Queued)
        ->and($fresh->lines)->toHaveCount(1)
        ->and($fresh->issuer->is($issuer))->toBeTrue();

    app(TenantContext::class)->bind($tenant, null, Environment::Production);
    expect(Document::forCurrentEnvironment()->find($doc->id))->toBeNull();
});

it('enforces the natural idempotency key', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer = Issuer::factory()->for($tenant)->create();
    Document::factory()->for($issuer)->create(['source_system' => 'catalog', 'source_ref' => 'o1', 'type' => 'invoice']);
    Document::factory()->for($issuer)->create(['source_system' => 'catalog', 'source_ref' => 'o1', 'type' => 'invoice']);
})->throws(\Illuminate\Database\UniqueConstraintViolationException::class);

it('knows the cancellation window', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    $issuer = Issuer::factory()->for($tenant)->create();
    $doc = Document::factory()->for($issuer)->valid()->create(['lhdn_status_at' => now()->subHours(71)]);
    expect($doc->isCancellable())->toBeTrue();
    $doc->lhdn_status_at = now()->subHours(73);
    expect($doc->isCancellable())->toBeFalse();
    $held = Document::factory()->for($issuer)->held(HeldReason::IssuerNotActive)->create();
    expect($held->isCancellable())->toBeFalse()->and($held->held_reason)->toBe(HeldReason::IssuerNotActive);
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Documents/DocumentModelTest.php` → FAIL.

- [ ] **Step 3: Migrations**

`database/migrations/2026_08_20_000001_create_documents_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('buyer_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('group_id')->nullable();
            $table->string('environment', 16);
            $table->string('type', 32);
            $table->string('status', 32);
            $table->string('held_reason', 32)->nullable();
            $table->json('buyer_snapshot');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->date('issue_date');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('total_excluding_tax', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total_including_tax', 18, 2)->default(0);
            $table->decimal('total_payable', 18, 2)->default(0);
            $table->boolean('consolidate')->default(false);
            $table->string('source_system', 50);
            $table->string('source_ref', 191);
            $table->ulid('original_document_id')->nullable();
            $table->string('original_lhdn_uuid', 64)->nullable();
            $table->json('payment')->nullable();
            $table->json('metadata')->nullable();
            $table->char('payload_hash', 64);
            $table->string('lhdn_uuid', 64)->nullable();
            $table->string('lhdn_long_id', 128)->nullable();
            $table->string('lhdn_submission_uid', 64)->nullable();
            $table->json('lhdn_errors')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('lhdn_status_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->ulid('consolidated_into_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'source_system', 'source_ref', 'type'], 'documents_natural_key_unique');
            $table->index(['tenant_id', 'environment', 'status']);
            $table->index(['tenant_id', 'issuer_id']);
            $table->index(['tenant_id', 'group_id']);
            $table->index(['tenant_id', 'lhdn_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```

`database/migrations/2026_08_20_000002_create_document_lines_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('classification_code', 3);
            $table->string('description', 300);
            $table->decimal('quantity', 18, 4);
            $table->string('unit_code', 10);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('discount_rate', 8, 4)->nullable();
            $table->string('tax_type', 2);
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->string('tax_exemption_reason', 300)->nullable();
            $table->decimal('subtotal', 18, 2);
            $table->decimal('total', 18, 2);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'position']);
            $table->index(['tenant_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lines');
    }
};
```

`database/migrations/2026_08_20_000003_create_document_events_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason', 64)->nullable();
            $table->json('meta')->nullable();
            $table->string('actor_type', 20)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['document_id', 'created_at']);
            $table->index(['tenant_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_events');
    }
};
```

- [ ] **Step 4: Models**

`app/Models/Document.php`
```php
<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $issuer_id
 * @property string|null $buyer_id
 * @property string|null $group_id
 * @property Environment $environment
 * @property DocumentType $type
 * @property DocumentStatus $status
 * @property HeldReason|null $held_reason
 * @property array<string, mixed> $buyer_snapshot
 * @property string $currency
 * @property string|null $exchange_rate
 * @property Carbon $issue_date
 * @property string $subtotal
 * @property string $discount_total
 * @property string $total_excluding_tax
 * @property string $tax_total
 * @property string $total_including_tax
 * @property string $total_payable
 * @property bool $consolidate
 * @property string $source_system
 * @property string $source_ref
 * @property string|null $original_document_id
 * @property string|null $original_lhdn_uuid
 * @property array<string, mixed>|null $payment
 * @property array<string, mixed>|null $metadata
 * @property string $payload_hash
 * @property string|null $lhdn_uuid
 * @property string|null $lhdn_long_id
 * @property string|null $lhdn_submission_uid
 * @property array<int, mixed>|null $lhdn_errors
 * @property Carbon|null $validated_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $lhdn_status_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $consolidated_into_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Issuer $issuer
 * @property-read Buyer|null $buyer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DocumentLine> $lines
 * @property-read \Illuminate\Database\Eloquent\Collection<int, DocumentEvent> $events
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const CANCELLATION_WINDOW_HOURS = 72;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'held_reason' => HeldReason::class,
            'buyer_snapshot' => 'array',
            'payment' => 'array',
            'metadata' => 'array',
            'lhdn_errors' => 'array',
            'consolidate' => 'boolean',
            'issue_date' => 'date:Y-m-d',
            'validated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'lhdn_status_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Issuer, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class);
    }

    /** @return BelongsTo<Buyer, $this> */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function originalDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'original_document_id');
    }

    /** @return HasMany<DocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('position');
    }

    /** @return HasMany<DocumentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DocumentEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /** @param  Builder<Document>  $query */
    public function scopeForCurrentEnvironment(Builder $query): void
    {
        $query->where('environment', app(TenantContext::class)->environment());
    }

    public function isCancellable(): bool
    {
        return $this->status === DocumentStatus::Valid
            && $this->lhdn_status_at !== null
            && $this->lhdn_status_at->copy()->addHours(self::CANCELLATION_WINDOW_HOURS)->isFuture();
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return static::forCurrentEnvironment()->where($field ?? $this->getRouteKeyName(), $value)->first();
    }
}
```
Decimal columns come back from PDO as strings on MySQL and as numeric on SQLite; add `'total_payable' => 'string'`… Laravel has no string cast for decimals — instead cast all six money columns and `exchange_rate` with `'decimal:2'` / `'decimal:6'` (Laravel's decimal cast returns a fixed-scale string on every driver). Add to `casts()`: `'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'total_excluding_tax' => 'decimal:2', 'tax_total' => 'decimal:2', 'total_including_tax' => 'decimal:2', 'total_payable' => 'decimal:2', 'exchange_rate' => 'decimal:6'`.

`app/Models/DocumentLine.php`
```php
<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Database\Factories\DocumentLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property int $position
 * @property string $classification_code
 * @property string $description
 * @property string $quantity
 * @property string $unit_code
 * @property string $unit_price
 * @property string $discount_amount
 * @property string|null $discount_rate
 * @property string $tax_type
 * @property string|null $tax_rate
 * @property string $tax_amount
 * @property string|null $tax_exemption_reason
 * @property string $subtotal
 * @property string $total
 * @property array<string, mixed>|null $metadata
 */
class DocumentLine extends Model
{
    /** @use HasFactory<DocumentLineFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'discount_rate' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
```

`app/Models/DocumentEvent.php`
```php
<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property DocumentStatus|null $from_status
 * @property DocumentStatus $to_status
 * @property string|null $reason
 * @property array<string, mixed>|null $meta
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property Carbon $created_at
 */
class DocumentEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => DocumentStatus::class,
            'to_status' => DocumentStatus::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
```

- [ ] **Step 5: Factories**

`database/factories/DocumentFactory.php`
```php
<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Models\Issuer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'issuer_id' => Issuer::factory(),
            'environment' => Environment::Sandbox,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'buyer_snapshot' => ['general_public' => true, 'name' => 'General Public', 'tin' => 'EI00000000010', 'id_type' => 'BRN', 'id_number' => 'NA'],
            'currency' => 'MYR',
            'issue_date' => now()->toDateString(),
            'subtotal' => '0.00', 'discount_total' => '0.00', 'total_excluding_tax' => '0.00',
            'tax_total' => '0.00', 'total_including_tax' => '0.00', 'total_payable' => '0.00',
            'consolidate' => false,
            'source_system' => 'test',
            'source_ref' => 'ref-'.Str::lower(Str::random(10)),
            'payload_hash' => hash('sha256', Str::random(16)),
        ];
    }

    public function queued(): static
    {
        return $this->state(['status' => DocumentStatus::Queued, 'validated_at' => now()]);
    }

    public function held(HeldReason $reason): static
    {
        return $this->state(['status' => DocumentStatus::Held, 'held_reason' => $reason, 'validated_at' => now()]);
    }

    public function valid(): static
    {
        return $this->state([
            'status' => DocumentStatus::Valid, 'validated_at' => now(), 'submitted_at' => now(),
            'lhdn_uuid' => Str::upper(Str::random(26)), 'lhdn_status_at' => now(),
        ]);
    }
}
```
Factory tenant: `Document` is tenant-owned; tests bind `TenantContext` before creating so `BelongsToTenant` auto-fills `tenant_id`, and `Issuer::factory()` inside `issuer_id` provides `tenant_id => Tenant::factory()` — in tests always pass `->for($issuer)` with an issuer of the bound tenant (as the test above does).

`database/factories/DocumentLineFactory.php`
```php
<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentLine> */
class DocumentLineFactory extends Factory
{
    protected $model = DocumentLine::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'position' => 1,
            'classification_code' => '022',
            'description' => 'Item',
            'quantity' => '1.0000',
            'unit_code' => 'C62',
            'unit_price' => '10.0000',
            'discount_amount' => '0.00',
            'tax_type' => '06',
            'tax_amount' => '0.00',
            'subtotal' => '10.00',
            'total' => '10.00',
        ];
    }
}
```

- [ ] **Step 6: Run tests** — `vendor/bin/pest tests/Unit/Documents/DocumentModelTest.php` → pass; then the full `composer check` (green). If SQLite returns decimals as floats and the `decimal:2` cast produces `"12.5"` vs `"12.50"`, Laravel's decimal cast uses `number_format` and yields `"12.50"` — assert exactly `'12.50'`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_20_* database/factories/DocumentFactory.php database/factories/DocumentLineFactory.php app/Models/Document.php app/Models/DocumentLine.php app/Models/DocumentEvent.php tests/Unit/Documents/DocumentModelTest.php
git commit -m "feat(documents): documents, document_lines, document_events storage with models and factories"
```

---
### Task 6: `DocumentStateMachine` + `DocumentTransitioned` event

**Files:**
- Create: `app/Domain/Documents/InvalidTransition.php`, `app/Domain/Documents/CancellationWindowClosed.php`, `app/Domain/Documents/DocumentStateMachine.php`, `app/Events/DocumentTransitioned.php`, `tests/Unit/Documents/DocumentStateMachineTest.php`

**Interfaces:**
- Consumes: `Document`, `DocumentEvent`, `DocumentStatus`, `HeldReason`, `TenantContext` (for actor), `Document::isCancellable()`.
- Produces:
  - `DocumentStateMachine::TRANSITIONS`: `draft→[validated]`, `validated→[queued, held, awaiting_consolidation]`, `held→[queued]`, `queued→[submitted, held]`, `submitted→[valid, invalid]`, `invalid→[queued]`, `valid→[cancelled, rejected]`, `awaiting_consolidation→[consolidated, queued]`; `cancelled/rejected/consolidated→[]`.
  - `DocumentStateMachine::canTransition(DocumentStatus $from, DocumentStatus $to): bool`
  - `DocumentStateMachine::transition(Document $document, DocumentStatus $to, ?string $reason = null, array $meta = []): DocumentEvent` — throws `InvalidTransition`; for `to = cancelled` throws `CancellationWindowClosed` when `!$document->isCancellable()`; sets `held_reason` (from `$reason` when `to = held`, else null when leaving held), timestamps (`validated_at` on validated, `submitted_at` on submitted, `lhdn_status_at` on valid/invalid, `cancelled_at` + `cancel_reason` on cancelled); saves the document; creates the `DocumentEvent` (actor from `TenantContext`); dispatches `DocumentTransitioned`; all inside `DB::transaction`.
  - `App\Events\DocumentTransitioned(public readonly Document $document, public readonly ?DocumentStatus $from, public readonly DocumentStatus $to, public readonly ?string $reason)` (plain event, `Dispatchable`).
  - Problem mapping (used by Task 9): `InvalidTransition` → 409 `invalid_transition`; `CancellationWindowClosed` → 409 `cancellation_window_closed`.

- [ ] **Step 1: Write failing tests `tests/Unit/Documents/DocumentStateMachineTest.php`**

```php
<?php

use App\Domain\Documents\CancellationWindowClosed;
use App\Domain\Documents\DocumentStateMachine;
use App\Domain\Documents\InvalidTransition;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->create();
    $this->sm = new DocumentStateMachine;
});

dataset('allowed', [
    ['draft', 'validated'], ['validated', 'queued'], ['validated', 'held'], ['validated', 'awaiting_consolidation'],
    ['held', 'queued'], ['queued', 'submitted'], ['queued', 'held'], ['submitted', 'valid'], ['submitted', 'invalid'],
    ['invalid', 'queued'], ['valid', 'rejected'], ['awaiting_consolidation', 'consolidated'], ['awaiting_consolidation', 'queued'],
]);
dataset('forbidden', [
    ['draft', 'queued'], ['queued', 'valid'], ['valid', 'queued'], ['cancelled', 'queued'], ['consolidated', 'queued'], ['held', 'valid'],
]);

it('allows documented transitions', function (string $from, string $to) {
    expect($this->sm->canTransition(DocumentStatus::from($from), DocumentStatus::from($to)))->toBeTrue();
})->with('allowed');

it('forbids everything else', function (string $from, string $to) {
    expect($this->sm->canTransition(DocumentStatus::from($from), DocumentStatus::from($to)))->toBeFalse();
    $doc = Document::factory()->for($this->issuer)->create(['status' => $from]);
    expect(fn () => $this->sm->transition($doc, DocumentStatus::from($to)))->toThrow(InvalidTransition::class);
})->with('forbidden');

it('records an event, sets timestamps and held reason, and dispatches DocumentTransitioned', function () {
    Event::fake([DocumentTransitioned::class]);
    $doc = Document::factory()->for($this->issuer)->create(['status' => 'draft']);
    $this->sm->transition($doc, DocumentStatus::Validated);
    $this->sm->transition($doc, DocumentStatus::Held, HeldReason::IssuerNotActive->value);
    expect($doc->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($doc->held_reason)->toBe(HeldReason::IssuerNotActive)
        ->and($doc->validated_at)->not->toBeNull()
        ->and($doc->events()->count())->toBe(2)
        ->and($doc->events()->latest('created_at')->first()->reason)->toBe('issuer_not_active');
    $this->sm->transition($doc, DocumentStatus::Queued, 'issuer_activated');
    expect($doc->refresh()->held_reason)->toBeNull();
    Event::assertDispatched(DocumentTransitioned::class, 3);
});

it('enforces the 72h cancellation window', function () {
    $doc = Document::factory()->for($this->issuer)->valid()->create(['lhdn_status_at' => now()->subHours(73)]);
    expect(fn () => $this->sm->transition($doc, DocumentStatus::Cancelled, 'wrong buyer'))->toThrow(CancellationWindowClosed::class);
    $fresh = Document::factory()->for($this->issuer)->valid()->create(['lhdn_status_at' => now()->subHour()]);
    $this->sm->transition($fresh, DocumentStatus::Cancelled, 'wrong buyer');
    expect($fresh->refresh()->status)->toBe(DocumentStatus::Cancelled)->and($fresh->cancel_reason)->toBe('wrong buyer')->and($fresh->cancelled_at)->not->toBeNull();
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Unit/Documents/DocumentStateMachineTest.php` → FAIL.

- [ ] **Step 3: Implement**

`app/Domain/Documents/InvalidTransition.php`
```php
<?php

namespace App\Domain\Documents;

use App\Enums\DocumentStatus;
use RuntimeException;

final class InvalidTransition extends RuntimeException
{
    public function __construct(public readonly DocumentStatus $from, public readonly DocumentStatus $to)
    {
        parent::__construct("Cannot transition document from {$from->value} to {$to->value}.");
    }
}
```

`app/Domain/Documents/CancellationWindowClosed.php`
```php
<?php

namespace App\Domain\Documents;

use RuntimeException;

final class CancellationWindowClosed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The 72-hour cancellation window has closed; issue a credit or refund note instead.');
    }
}
```

`app/Events/DocumentTransitioned.php`
```php
<?php

namespace App\Events;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly Document $document,
        public readonly ?DocumentStatus $from,
        public readonly DocumentStatus $to,
        public readonly ?string $reason,
    ) {}
}
```

`app/Domain/Documents/DocumentStateMachine.php`
```php
<?php

namespace App\Domain\Documents;

use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DocumentStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'draft' => ['validated'],
        'validated' => ['queued', 'held', 'awaiting_consolidation'],
        'held' => ['queued'],
        'queued' => ['submitted', 'held'],
        'submitted' => ['valid', 'invalid'],
        'invalid' => ['queued'],
        'valid' => ['cancelled', 'rejected'],
        'awaiting_consolidation' => ['consolidated', 'queued'],
        'cancelled' => [],
        'rejected' => [],
        'consolidated' => [],
    ];

    public function canTransition(DocumentStatus $from, DocumentStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    /** @param array<string, mixed> $meta */
    public function transition(Document $document, DocumentStatus $to, ?string $reason = null, array $meta = []): DocumentEvent
    {
        $from = $document->status;
        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransition($from, $to);
        }
        if ($to === DocumentStatus::Cancelled && ! $document->isCancellable()) {
            throw new CancellationWindowClosed;
        }

        return DB::transaction(function () use ($document, $from, $to, $reason, $meta): DocumentEvent {
            $now = now();
            $document->status = $to;
            $document->held_reason = $to === DocumentStatus::Held && $reason !== null ? HeldReason::from($reason) : null;
            match ($to) {
                DocumentStatus::Validated => $document->validated_at = $now,
                DocumentStatus::Submitted => $document->submitted_at = $now,
                DocumentStatus::Valid, DocumentStatus::Invalid => $document->lhdn_status_at = $now,
                DocumentStatus::Cancelled => [$document->cancelled_at = $now, $document->cancel_reason = $reason],
                default => null,
            };
            $document->save();

            $actor = app(TenantContext::class)->actor();
            $event = $document->events()->create([
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason !== null ? mb_substr($reason, 0, 64) : null,
                'meta' => $meta === [] ? null : $meta,
                'actor_type' => $actor?->type,
                'actor_id' => $actor?->id,
                'created_at' => $now,
            ]);

            DocumentTransitioned::dispatch($document, $from, $to, $reason);

            return $event;
        });
    }
}
```
(`$document->events()->create([...])` — `DocumentEvent` uses `BelongsToTenant`, so `tenant_id` is auto-filled from context; `document_id` from the relation.)

- [ ] **Step 4: Run tests** — pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Documents/InvalidTransition.php app/Domain/Documents/CancellationWindowClosed.php app/Domain/Documents/DocumentStateMachine.php app/Events/DocumentTransitioned.php tests/Unit/Documents/DocumentStateMachineTest.php
git commit -m "feat(documents): DocumentStateMachine with event log, cancellation window and DocumentTransitioned event"
```

---

### Task 7: `CreateDocument`, `SubmitDocument`, semantic validation, buyer snapshot

**Files:**
- Create: `app/Actions/Documents/ResolveBuyerSnapshot.php`, `app/Actions/Documents/DocumentSemanticValidator.php`, `app/Actions/Documents/CreateDocument.php`, `app/Actions/Documents/CreateDocumentBatch.php`, `app/Actions/Documents/SubmitDocument.php`, `app/Actions/Documents/DocumentCreated.php` (result value object), `tests/Feature/Documents/CreateDocumentActionTest.php`

**Interfaces:**
- Consumes: `CreateDocumentData` (+ `payloadHash()`), `TotalsCalculator`/`TotalsMismatch`, `DocumentStateMachine`, models, `TenantContext`, `Issuer`, `Buyer`, `ProblemException`, `HeldReason`, `IssuerStatus`.
- Produces:
  - `ResolveBuyerSnapshot::resolve(DocumentBuyerData $buyer): array{buyer_id: ?string, snapshot: array<string, mixed>}` — modes: `buyer_id` → tenant's `Buyer` (404 problem `buyer_not_found` if missing) snapshot of its fields + `general_public` from the record; `general_public` → `{general_public: true, name: 'General Public', tin: 'EI00000000010', id_type: 'BRN', id_number: 'NA', country_code: 'MYS'}`; `inline` → the inline fields; `invalid` → `ValidationException::withMessages(['buyer' => 'Provide exactly one of buyer_id, general_public=true, or inline buyer fields (name…).'])`.
  - `DocumentSemanticValidator::validate(CreateDocumentData $data, Issuer $issuer): void` — throws `ValidationException::withMessages([...])` for: `original_document_ref` missing when `type->requiresOriginalRef()` (`original_document_ref`), present when not a note (`original_document_ref`), `original_document_ref.document_id` not found in tenant+env (`original_document_ref.document_id`); `consolidate=true` but buyer not general public (`consolidate`) or `issuer.consolidation_enabled=false` (`consolidate`); `metadata` JSON > 8192 bytes (`metadata`); `currency != MYR` without `exchange_rate` is already a DTO rule.
  - `DocumentCreated { public function __construct(public readonly Document $document, public readonly bool $replayed) {} }`
  - `CreateDocument::handle(CreateDocumentData $data, ?string $groupId = null): DocumentCreated` — steps: resolve issuer (`Issuer::forCurrentEnvironment()->find($data->issuer_id)` else `ProblemException::notFound('Issuer not found.')` with code `issuer_not_found` — use `new ProblemException(404, 'Not Found', 'Issuer not found.', 'issuer_not_found')`); natural-key check (existing with same hash → `DocumentCreated(existing, replayed: true)`; different hash → `ProblemException::conflict('A document with this source reference already exists with a different payload.', 'idempotency_conflict')`); semantic validate; buyer snapshot; totals (`TotalsMismatch` → `ValidationException::withMessages([ltrim(str_replace('/', '.', $pointer), '.') => "…expected X, got Y"])` — pointer `/lines/0/total` becomes key `lines.0.total`); persist document + lines in a transaction (`environment` = issuer env, `status` draft, `issue_date` default today in `Asia/Kuala_Lumpur`); transition draft→validated; then `route()`; catch `UniqueConstraintViolationException` on insert → re-run the natural-key check (concurrent duplicate) and return replay/409 accordingly.
  - Routing after validated (private `route(Document, Issuer, CreateDocumentData)`): `!$data->submit` → stay validated (draft-like; spec calls it draft: leave status `validated`); `!$issuer->einvoice_required` → held(`einvoice_not_required`); `$data->consolidate` → awaiting_consolidation; issuer status ≠ active → held(`issuer_not_active`) (or `certificate_expired` if `status = suspended`); else queued.
  - `SubmitDocument::handle(Document $document): Document` — allowed from `validated` or `held`; if issuer not active → 409 `issuer_not_active` (`ProblemException::conflict`); if `!einvoice_required` → 409 `einvoice_not_required`; else transition to queued (reason `manual_submit`); any other status → 409 `invalid_transition`.
  - `CreateDocumentBatch::handle(CreateDocumentBatchData $batch): array{group_id: string, documents: list<DocumentCreated>}` — validate ALL items first without persisting (issuer lookup, semantic validation, buyer resolution, totals) collecting errors keyed `documents.{i}.…` into ONE `ValidationException`; then persist all in a single `DB::transaction` via `CreateDocument::handle($item, $groupId)`; `group_id = (string) Str::ulid()`.

- [ ] **Step 1: Write failing tests `tests/Feature/Documents/CreateDocumentActionTest.php`** (Feature dir → RefreshDatabase via Pest.php)

```php
<?php

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\SubmitDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Exceptions\ProblemException;
use App\Models\Buyer;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

function docPayload(Issuer $issuer, array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'invoice',
        'issuer_id' => $issuer->id,
        'buyer' => ['general_public' => true],
        'lines' => [[
            'classification_code' => '022', 'description' => 'Widget', 'quantity' => 2,
            'unit_code' => 'C62', 'unit_price' => '10.50', 'tax_type' => '02', 'tax_rate' => 6,
        ]],
        'source' => ['system' => 'catalog', 'ref' => 'order-'.bin2hex(random_bytes(3))],
    ], $overrides);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->create = app(CreateDocument::class);
});

it('creates a validated document, computes totals, snapshots the buyer and queues it', function () {
    $r = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer)));
    $doc = $r->document->refresh();
    expect($r->replayed)->toBeFalse()
        ->and($doc->status)->toBe(DocumentStatus::Queued)
        ->and($doc->environment)->toBe(Environment::Sandbox)
        ->and($doc->total_payable)->toBe('22.26')
        ->and($doc->tax_total)->toBe('1.26')
        ->and($doc->buyer_snapshot['tin'])->toBe('EI00000000010')
        ->and($doc->lines)->toHaveCount(1)
        ->and($doc->lines[0]->total)->toBe('22.26')
        ->and($doc->events()->pluck('to_status')->map->value->all())->toBe(['validated', 'queued']);
});

it('replays on identical natural key + payload and conflicts on a different payload', function () {
    $payload = docPayload($this->issuer);
    $a = $this->create->handle(CreateDocumentData::from($payload));
    $b = $this->create->handle(CreateDocumentData::from($payload));
    expect($b->replayed)->toBeTrue()->and($b->document->id)->toBe($a->document->id);
    $payload['lines'][0]['quantity'] = 3;
    expect(fn () => $this->create->handle(CreateDocumentData::from($payload)))
        ->toThrow(fn (ProblemException $e) => expect($e->status)->toBe(409)->and($e->problemCode)->toBe('idempotency_conflict'));
});

it('holds documents for inactive issuers and for issuers below the threshold', function () {
    $draftIssuer = Issuer::factory()->for($this->tenant)->create();
    $r = $this->create->handle(CreateDocumentData::from(docPayload($draftIssuer)));
    expect($r->document->status)->toBe(DocumentStatus::Held)->and($r->document->held_reason)->toBe(HeldReason::IssuerNotActive);

    $small = Issuer::factory()->for($this->tenant)->active()->create(['einvoice_required' => false]);
    $r2 = $this->create->handle(CreateDocumentData::from(docPayload($small)));
    expect($r2->document->held_reason)->toBe(HeldReason::EinvoiceNotRequired);
});

it('routes consolidated B2C documents to awaiting_consolidation and rejects consolidation misuse', function () {
    $issuer = Issuer::factory()->for($this->tenant)->active()->create(['consolidation_enabled' => true]);
    $r = $this->create->handle(CreateDocumentData::from(docPayload($issuer, ['consolidate' => true])));
    expect($r->document->status)->toBe(DocumentStatus::AwaitingConsolidation);

    $buyer = Buyer::factory()->for($this->tenant)->create();
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($issuer, ['consolidate' => true, 'buyer' => ['general_public' => false, 'buyer_id' => $buyer->id]]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('consolidate'));
});

it('resolves buyer_id to a snapshot and 404s unknown buyers; requires original refs on notes', function () {
    $buyer = Buyer::factory()->for($this->tenant)->create(['name' => 'Ali']);
    $r = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['buyer' => ['general_public' => false, 'buyer_id' => $buyer->id]])));
    expect($r->document->buyer_id)->toBe($buyer->id)->and($r->document->buyer_snapshot['name'])->toBe('Ali');

    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['buyer' => ['general_public' => false, 'buyer_id' => '01J00000000000000000000000']]))))
        ->toThrow(fn (ProblemException $e) => expect($e->status)->toBe(404)->and($e->problemCode)->toBe('buyer_not_found'));

    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['type' => 'credit_note']))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('original_document_ref'));

    $note = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['type' => 'credit_note', 'original_document_ref' => ['document_id' => $r->document->id]])));
    expect($note->document->original_document_id)->toBe($r->document->id);
});

it('maps totals mismatches to validation errors with dotted keys', function () {
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['lines' => [['total' => '99.00']]]))))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('lines.0.total'));
});

it('keeps submit=false documents at validated and SubmitDocument queues them', function () {
    $r = $this->create->handle(CreateDocumentData::from(docPayload($this->issuer, ['submit' => false])));
    expect($r->document->status)->toBe(DocumentStatus::Validated);
    $doc = app(SubmitDocument::class)->handle($r->document);
    expect($doc->status)->toBe(DocumentStatus::Queued);
    expect(fn () => app(SubmitDocument::class)->handle($doc))
        ->toThrow(fn (ProblemException $e) => expect($e->problemCode)->toBe('invalid_transition'));
});

it('404s an issuer from another environment or tenant', function () {
    $prod = Issuer::factory()->for($this->tenant)->active()->create(['environment' => Environment::Production]);
    expect(fn () => $this->create->handle(CreateDocumentData::from(docPayload($prod))))
        ->toThrow(fn (ProblemException $e) => expect($e->problemCode)->toBe('issuer_not_found'));
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/Documents/CreateDocumentActionTest.php` → FAIL.

- [ ] **Step 3: Implement**

`app/Actions/Documents/DocumentCreated.php`
```php
<?php

namespace App\Actions\Documents;

use App\Models\Document;

final class DocumentCreated
{
    public function __construct(public readonly Document $document, public readonly bool $replayed) {}
}
```

`app/Actions/Documents/ResolveBuyerSnapshot.php`
```php
<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\DocumentBuyerData;
use App\Exceptions\ProblemException;
use App\Models\Buyer;
use Illuminate\Validation\ValidationException;

class ResolveBuyerSnapshot
{
    public const GENERAL_PUBLIC_TIN = 'EI00000000010';

    /** @return array{buyer_id: ?string, snapshot: array<string, mixed>} */
    public function resolve(DocumentBuyerData $buyer): array
    {
        return match ($buyer->mode()) {
            'buyer_id' => $this->fromRegistry((string) $buyer->buyer_id),
            'general_public' => ['buyer_id' => null, 'snapshot' => [
                'general_public' => true, 'name' => 'General Public', 'tin' => self::GENERAL_PUBLIC_TIN,
                'id_type' => 'BRN', 'id_number' => 'NA', 'country_code' => 'MYS',
            ]],
            'inline' => ['buyer_id' => null, 'snapshot' => array_filter([
                'general_public' => false, 'name' => $buyer->name, 'tin' => $buyer->tin,
                'id_type' => $buyer->id_type?->value, 'id_number' => $buyer->id_number, 'sst_number' => $buyer->sst_number,
                'email' => $buyer->email, 'phone' => $buyer->phone,
                'address_line1' => $buyer->address_line1, 'address_line2' => $buyer->address_line2, 'address_line3' => $buyer->address_line3,
                'postcode' => $buyer->postcode, 'city' => $buyer->city, 'state_code' => $buyer->state_code,
                'country_code' => $buyer->country_code ?? 'MYS',
            ], fn ($v) => $v !== null)],
            default => throw ValidationException::withMessages([
                'buyer' => 'Provide exactly one of buyer_id, general_public=true, or inline buyer fields (name, …).',
            ]),
        };
    }

    /** @return array{buyer_id: ?string, snapshot: array<string, mixed>} */
    private function fromRegistry(string $id): array
    {
        $record = Buyer::query()->find($id) ?? throw new ProblemException(404, 'Not Found', 'Buyer not found.', 'buyer_not_found');

        return ['buyer_id' => $record->id, 'snapshot' => array_filter([
            'general_public' => $record->general_public, 'name' => $record->name, 'tin' => $record->tin,
            'id_type' => $record->id_type?->value, 'id_number' => $record->id_number, 'sst_number' => $record->sst_number,
            'email' => $record->email, 'phone' => $record->phone,
            'address_line1' => $record->address_line1, 'address_line2' => $record->address_line2, 'address_line3' => $record->address_line3,
            'postcode' => $record->postcode, 'city' => $record->city, 'state_code' => $record->state_code,
            'country_code' => $record->country_code,
        ], fn ($v) => $v !== null)];
    }
}
```

`app/Actions/Documents/DocumentSemanticValidator.php`
```php
<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\CreateDocumentData;
use App\Models\Document;
use App\Models\Issuer;
use Illuminate\Validation\ValidationException;

class DocumentSemanticValidator
{
    public const METADATA_MAX_BYTES = 8192;

    /** @return array<string, string> errors keyed by dotted field; empty when valid */
    public function errors(CreateDocumentData $data, Issuer $issuer): array
    {
        $errors = [];
        if ($data->type->requiresOriginalRef() && $data->original_document_ref === null) {
            $errors['original_document_ref'] = 'Credit, debit and refund notes must reference the original document.';
        }
        if (! $data->type->requiresOriginalRef() && $data->original_document_ref !== null) {
            $errors['original_document_ref'] = 'Only credit, debit and refund notes may reference an original document.';
        }
        if ($data->original_document_ref?->document_id !== null
            && ! Document::forCurrentEnvironment()->whereKey($data->original_document_ref->document_id)->exists()) {
            $errors['original_document_ref.document_id'] = 'Original document not found.';
        }
        if ($data->consolidate) {
            if (! $data->buyer->general_public) {
                $errors['consolidate'] = 'Only general-public (B2C) documents can be consolidated.';
            } elseif (! $issuer->consolidation_enabled) {
                $errors['consolidate'] = 'Consolidation is not enabled for this issuer.';
            }
        }
        if ($data->metadata !== null && strlen((string) json_encode($data->metadata)) > self::METADATA_MAX_BYTES) {
            $errors['metadata'] = 'metadata must not exceed 8 KB.';
        }

        return $errors;
    }

    public function validate(CreateDocumentData $data, Issuer $issuer): void
    {
        $errors = $this->errors($data, $issuer);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
```

`app/Actions/Documents/CreateDocument.php`
```php
<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\CreateDocumentData;
use App\Domain\Documents\DocumentStateMachine;
use App\Domain\Documents\TotalsCalculator;
use App\Domain\Documents\TotalsMismatch;
use App\Domain\Documents\Money;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Models\Document;
use App\Models\Issuer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateDocument
{
    public function __construct(
        private readonly DocumentSemanticValidator $semantics,
        private readonly ResolveBuyerSnapshot $buyers,
        private readonly TotalsCalculator $totals,
        private readonly DocumentStateMachine $stateMachine,
    ) {}

    public function handle(CreateDocumentData $data, ?string $groupId = null): DocumentCreated
    {
        $issuer = $this->issuer($data);
        if ($existing = $this->existingByNaturalKey($data)) {
            return $existing;
        }
        $this->semantics->validate($data, $issuer);
        $buyer = $this->buyers->resolve($data->buyer);
        try {
            $totals = $this->totals->calculate($data->lines, $data->totals);
        } catch (TotalsMismatch $e) {
            throw ValidationException::withMessages([
                str_replace('/', '.', ltrim($e->pointer, '/')) => "Value {$e->given} does not match the computed {$e->expected}.",
            ]);
        }

        try {
            $document = DB::transaction(function () use ($data, $issuer, $buyer, $totals, $groupId): Document {
                $document = Document::create([
                    'issuer_id' => $issuer->id,
                    'buyer_id' => $buyer['buyer_id'],
                    'group_id' => $groupId,
                    'environment' => $issuer->environment,
                    'type' => $data->type,
                    'status' => DocumentStatus::Draft,
                    'buyer_snapshot' => $buyer['snapshot'],
                    'currency' => $data->currency,
                    'exchange_rate' => $data->exchange_rate !== null ? Money::str(Money::of($data->exchange_rate), 6) : null,
                    'issue_date' => $data->issue_date ?? now('Asia/Kuala_Lumpur')->toDateString(),
                    'consolidate' => $data->consolidate,
                    'source_system' => $data->source->system,
                    'source_ref' => $data->source->ref,
                    'original_document_id' => $data->original_document_ref?->document_id,
                    'original_lhdn_uuid' => $data->original_document_ref?->lhdn_uuid,
                    'payment' => $data->payment?->toArray(),
                    'metadata' => $data->metadata,
                    'payload_hash' => $data->payloadHash(),
                ] + $totals->toStrings());

                foreach ($data->lines as $i => $line) {
                    $lt = $totals->lines[$i];
                    $document->lines()->create([
                        'position' => $i + 1,
                        'classification_code' => $line->classification_code,
                        'description' => $line->description,
                        'quantity' => Money::str($lt->quantity, 4),
                        'unit_code' => $line->unit_code,
                        'unit_price' => Money::str($lt->unitPrice, 4),
                        'discount_amount' => Money::str($lt->discount),
                        'discount_rate' => $line->discount_rate !== null ? Money::str(Money::of($line->discount_rate), 4) : null,
                        'tax_type' => $line->tax_type,
                        'tax_rate' => $lt->taxRate !== null ? Money::str($lt->taxRate, 4) : null,
                        'tax_amount' => Money::str($lt->taxAmount),
                        'tax_exemption_reason' => $line->tax_exemption_reason,
                        'subtotal' => Money::str($lt->subtotal),
                        'total' => Money::str($lt->total),
                        'metadata' => $line->metadata,
                    ]);
                }

                $this->stateMachine->transition($document, DocumentStatus::Validated);
                $this->route($document, $issuer, $data);

                return $document;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with an identical create: settle it exactly like the pre-check would.
            return $this->existingByNaturalKey($data)
                ?? throw ProblemException::conflict('A document with this source reference already exists.', 'idempotency_conflict');
        }

        return new DocumentCreated($document, replayed: false);
    }

    private function issuer(CreateDocumentData $data): Issuer
    {
        return Issuer::forCurrentEnvironment()->find($data->issuer_id)
            ?? throw new ProblemException(404, 'Not Found', 'Issuer not found.', 'issuer_not_found');
    }

    private function existingByNaturalKey(CreateDocumentData $data): ?DocumentCreated
    {
        $existing = Document::query()
            ->where('source_system', $data->source->system)
            ->where('source_ref', $data->source->ref)
            ->where('type', $data->type)
            ->first();
        if ($existing === null) {
            return null;
        }
        if ($existing->payload_hash !== $data->payloadHash()) {
            throw ProblemException::conflict('A document with this source reference already exists with a different payload.', 'idempotency_conflict');
        }

        return new DocumentCreated($existing, replayed: true);
    }

    private function route(Document $document, Issuer $issuer, CreateDocumentData $data): void
    {
        if (! $data->submit) {
            return; // stays validated until POST /documents/{id}/submit
        }
        if (! $issuer->einvoice_required) {
            $this->stateMachine->transition($document, DocumentStatus::Held, HeldReason::EinvoiceNotRequired->value);

            return;
        }
        if ($data->consolidate) {
            $this->stateMachine->transition($document, DocumentStatus::AwaitingConsolidation);

            return;
        }
        if ($issuer->status !== IssuerStatus::Active) {
            $reason = $issuer->status === IssuerStatus::Suspended ? HeldReason::CertificateExpired : HeldReason::IssuerNotActive;
            $this->stateMachine->transition($document, DocumentStatus::Held, $reason->value);

            return;
        }
        $this->stateMachine->transition($document, DocumentStatus::Queued);
    }
}
```

`app/Actions/Documents/SubmitDocument.php`
```php
<?php

namespace App\Actions\Documents;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Models\Document;

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
```

`app/Actions/Documents/CreateDocumentBatch.php`
```php
<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\CreateDocumentBatchData;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Domain\Documents\TotalsCalculator;
use App\Domain\Documents\TotalsMismatch;
use App\Exceptions\ProblemException;
use App\Models\Issuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateDocumentBatch
{
    public function __construct(
        private readonly CreateDocument $create,
        private readonly DocumentSemanticValidator $semantics,
        private readonly ResolveBuyerSnapshot $buyers,
        private readonly TotalsCalculator $totals,
    ) {}

    /** @return array{group_id: string, documents: list<DocumentCreated>} */
    public function handle(CreateDocumentBatchData $batch): array
    {
        $errors = [];
        foreach ($batch->documents as $i => $item) {
            foreach ($this->itemErrors($item) as $key => $message) {
                $errors["documents.{$i}.{$key}"] = $message;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $groupId = (string) Str::ulid();
        $created = DB::transaction(function () use ($batch, $groupId): array {
            $out = [];
            foreach ($batch->documents as $item) {
                $out[] = $this->create->handle($item, $groupId);
            }

            return $out;
        });

        return ['group_id' => $groupId, 'documents' => $created];
    }

    /** @return array<string, string> */
    private function itemErrors(CreateDocumentData $item): array
    {
        $issuer = Issuer::forCurrentEnvironment()->find($item->issuer_id);
        if ($issuer === null) {
            return ['issuer_id' => 'Issuer not found.'];
        }
        $errors = $this->semantics->errors($item, $issuer);
        try {
            $this->buyers->resolve($item->buyer);
        } catch (ValidationException $e) {
            $errors['buyer'] = $e->errors()['buyer'][0] ?? 'Invalid buyer.';
        } catch (ProblemException) {
            $errors['buyer.buyer_id'] = 'Buyer not found.';
        }
        try {
            $this->totals->calculate($item->lines, $item->totals);
        } catch (TotalsMismatch $e) {
            $errors[str_replace('/', '.', ltrim($e->pointer, '/'))] = "Value {$e->given} does not match the computed {$e->expected}.";
        }

        return $errors;
    }
}
```

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Feature/Documents/CreateDocumentActionTest.php` → pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Documents tests/Feature/Documents/CreateDocumentActionTest.php
git commit -m "feat(documents): CreateDocument/CreateDocumentBatch/SubmitDocument actions with idempotency, routing and semantic validation"
```

---

### Task 8: `Idempotency-Key` middleware

**Files:**
- Create: `app/Http/Middleware/IdempotencyKey.php`, `config/einvoice.php` (add `idempotency_ttl_hours`), `tests/Feature/Documents/IdempotencyKeyTest.php`
- Modify: `bootstrap/app.php` (alias `idempotency`), `.env.example` (`EINVOICE_IDEMPOTENCY_TTL_HOURS=24`)

**Interfaces:**
- Consumes: `TenantContext`, `Cache`, `ProblemException`.
- Produces: middleware alias `idempotency` for POST routes. Behaviour: header absent → pass through. Header present (1–128 chars, else 400 `invalid_idempotency_key`): key `idem:{tenant_id}:{sha256(key)}`; if cached: request-hash mismatch (`sha256(method|path|body)`) → 409 `idempotency_key_reused`; else replay stored `{status, headers[Content-Type], body}` with header `Idempotent-Replay: true`. If not cached: run request; when response status is 2xx, store `{status, content_type, body, request_hash}` for `config('einvoice.idempotency_ttl_hours')` hours. Non-2xx responses are not cached. Only applies once `TenantContext` has a tenant (it runs after `auth.api`+`tenant`; register it AFTER `EnsureTenantContext` in the priority list is not needed — it is a route middleware placed after `tenant` in the group).

- [ ] **Step 1: Write failing tests `tests/Feature/Documents/IdempotencyKeyTest.php`**

```php
<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->calls = 0;
    Route::middleware(['api', 'auth.api', 'tenant', 'idempotency'])->prefix('v1')->post('/_test/idem', function () {
        $this->calls++;

        return response()->json(['n' => $this->calls], 201);
    });
    Route::middleware(['api', 'auth.api', 'tenant', 'idempotency'])->prefix('v1')->post('/_test/idem-fail', fn () => response()->json(['bad' => true], 422));
    $this->tenant = Tenant::factory()->create();
});

it('replays a cached 2xx response for the same key and body', function () {
    $h = serviceHeaders($this->tenant) + ['Idempotency-Key' => 'k1'];
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 1])->assertCreated()->assertJson(['n' => 1])->assertHeaderMissing('Idempotent-Replay');
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 1])->assertCreated()->assertJson(['n' => 1])->assertHeader('Idempotent-Replay', 'true');
    expect($this->calls)->toBe(1);
});

it('rejects the same key with a different body', function () {
    $h = serviceHeaders($this->tenant) + ['Idempotency-Key' => 'k2'];
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 1])->assertCreated();
    $this->withHeaders($h)->postJson('/v1/_test/idem', ['a' => 2])->assertStatus(409)->assertJsonPath('code', 'idempotency_key_reused');
});

it('does not cache non-2xx responses and scopes keys per tenant', function () {
    $h = serviceHeaders($this->tenant) + ['Idempotency-Key' => 'k3'];
    $this->withHeaders($h)->postJson('/v1/_test/idem-fail', [])->assertStatus(422);
    $this->withHeaders($h)->postJson('/v1/_test/idem-fail', [])->assertStatus(422)->assertHeaderMissing('Idempotent-Replay');

    $other = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($this->tenant) + ['Idempotency-Key' => 'shared'])->postJson('/v1/_test/idem', ['a' => 1])->assertCreated();
    $this->withHeaders(serviceHeaders($other) + ['Idempotency-Key' => 'shared'])->postJson('/v1/_test/idem', ['a' => 1])->assertCreated()->assertHeaderMissing('Idempotent-Replay');
});

it('rejects malformed keys', function () {
    $this->withHeaders(serviceHeaders($this->tenant) + ['Idempotency-Key' => str_repeat('x', 129)])->postJson('/v1/_test/idem', [])
        ->assertStatus(400)->assertJsonPath('code', 'invalid_idempotency_key');
});
```

- [ ] **Step 2: Run to verify failure** — FAIL (alias unknown).

- [ ] **Step 3: Implement**

`config/einvoice.php` — add key:
```php
'idempotency_ttl_hours' => (int) env('EINVOICE_IDEMPOTENCY_TTL_HOURS', 24),
```
(and `EINVOICE_IDEMPOTENCY_TTL_HOURS=24` in `.env.example`).

`app/Http/Middleware/IdempotencyKey.php`
```php
<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyKey
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $next($request);
        }
        if (strlen($key) > 128) {
            throw ProblemException::badRequest('Idempotency-Key must be 1–128 characters.', 'invalid_idempotency_key');
        }
        $tenantId = $this->context->tenant()->getKey();
        $cacheKey = 'idem:'.$tenantId.':'.hash('sha256', $key);
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        /** @var array{status:int, content_type:?string, body:string, request_hash:string}|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            if ($cached['request_hash'] !== $requestHash) {
                throw ProblemException::conflict('This Idempotency-Key was already used with a different request.', 'idempotency_key_reused');
            }

            return response($cached['body'], $cached['status'], array_filter([
                'Content-Type' => $cached['content_type'],
                'Idempotent-Replay' => 'true',
            ]));
        }

        $response = $next($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type'),
                'body' => (string) $response->getContent(),
                'request_hash' => $requestHash,
            ], now()->addHours((int) config('einvoice.idempotency_ttl_hours', 24)));
        }

        return $response;
    }
}
```
Register alias in `bootstrap/app.php`: `'idempotency' => \App\Http\Middleware\IdempotencyKey::class,` inside the existing `$middleware->alias([...])`.

- [ ] **Step 4: Run tests** — pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/IdempotencyKey.php bootstrap/app.php config/einvoice.php .env.example tests/Feature/Documents/IdempotencyKeyTest.php
git commit -m "feat(http): Idempotency-Key middleware with per-tenant cached replay"
```

---
### Task 9: Document endpoints — resource DTOs, controllers, routes, sweep rows

**Files:**
- Create: `app/Data/Resources/DocumentLineResourceData.php`, `app/Data/Resources/DocumentTotalsData.php`, `app/Data/Resources/DocumentEventData.php`, `app/Data/Resources/DocumentData.php`, `app/Http/Controllers/Api/V1/DocumentController.php`, `app/Http/Controllers/Api/V1/DocumentBatchController.php`, `tests/Feature/Documents/DocumentEndpointsTest.php`
- Modify: `routes/api.php`, `app/Http/Problem/ProblemResponse.php` (map `InvalidTransition`, `CancellationWindowClosed`), `tests/Feature/TenantIsolationSweepTest.php` (rows)

**Interfaces:**
- Consumes: Tasks 5–8; `AuditLogger`; `ApiKey::ABILITIES`; helpers `serviceHeaders`, `apiKeyHeaders`.
- Produces:
  - `DocumentLineResourceData(int $position, string $classification_code, string $description, string $quantity, string $unit_code, string $unit_price, string $discount_amount, ?string $discount_rate, string $tax_type, ?string $tax_rate, string $tax_amount, ?string $tax_exemption_reason, string $subtotal, string $total, ?array $metadata)` with `fromModel(DocumentLine)`.
  - `DocumentTotalsData(string $subtotal, string $discount_total, string $total_excluding_tax, string $tax_total, string $total_including_tax, string $total_payable)`.
  - `DocumentEventData(string $id, ?string $from_status, string $to_status, ?string $reason, ?array $meta, ?string $actor_type, ?string $actor_id, string $created_at)` with `fromModel(DocumentEvent)`.
  - `DocumentData(string $id, string $issuer_id, ?string $buyer_id, ?string $group_id, string $environment, string $type, string $lhdn_type_code, string $status, ?string $held_reason, array $buyer, string $currency, ?string $exchange_rate, string $issue_date, DocumentTotalsData $totals, /** @var DocumentLineResourceData[] */ array $lines, bool $consolidate, array $source /*{system, ref}*/, ?array $original_document_ref /*{document_id, lhdn_uuid}|null*/, ?array $payment, ?array $metadata, ?array $lhdn /*{uuid,long_id,submission_uid,errors,status_at}|null*/, ?string $validated_at, ?string $submitted_at, ?string $cancelled_at, ?string $cancel_reason, ?string $consolidated_into_id, string $created_at, string $updated_at)` with `fromModel(Document)` (loads `lines` if not loaded).
  - Routes (inside `tenant` group): `POST /documents` (`ability:documents:write`, `idempotency`), `POST /documents/batch` (same), `POST /documents/{document}/submit` (`ability:documents:write`), `GET /documents`, `GET /documents/{document}`, `GET /documents/{document}/events` (`ability:read`).
  - `POST /documents` → `201` + `DocumentData` wrapped in `data`; on natural-key replay → `200` + header `Idempotent-Replay: true` (use `response()->json(['data' => DocumentData::fromModel($doc)->toArray()], 200, ['Idempotent-Replay' => 'true'])`).
  - `POST /documents/batch` → `201 {data: [DocumentData…], meta: {group_id, count}}`; 422 problem+json with pointers `/documents/{i}/…` on any failure (all-or-nothing).
  - `GET /documents` filters: `status`, `issuer_id`, `group_id`, `type`, `source_system`, `source_ref`, `issue_date_from`, `issue_date_to` (Y-m-d) — invalid enum values → 422 (validate via a small `DocumentFilterData` request DTO in `app/Data/Requests/Documents/DocumentFilterData.php` with all-`Optional`/nullable props and rules).
  - Audit actions: `document.created` (changes `{type, source, total_payable, status}`), `document.submitted`, `document.batch_created` (`{group_id, count}`).
  - `ProblemResponse::describe()` additions: `InvalidTransition` → `[409, 'Conflict', $e->getMessage(), 'invalid_transition', []]`, `CancellationWindowClosed` → `[409, 'Conflict', $e->getMessage(), 'cancellation_window_closed', []]`.

- [ ] **Step 1: Write failing tests `tests/Feature/Documents/DocumentEndpointsTest.php`**

```php
<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;

function apiDocPayload(Issuer $issuer, array $overrides = []): array
{
    return array_replace_recursive([
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true],
        'lines' => [['classification_code' => '022', 'description' => 'Widget', 'quantity' => 2, 'unit_code' => 'C62', 'unit_price' => '10.50', 'tax_type' => '02', 'tax_rate' => 6]],
        'source' => ['system' => 'catalog', 'ref' => 'order-'.bin2hex(random_bytes(3))],
    ], $overrides);
}

beforeEach(function () {
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
    $doc = Document::factory()->for($this->issuer)->queued()->create(['environment' => Environment::Sandbox]);
    $this->withHeaders($this->h)->getJson('/v1/documents?status=queued')->assertOk()->assertJsonCount(1, 'data');
    $this->withHeaders($this->h)->getJson('/v1/documents?status=nope')->assertStatus(422);
    $this->withHeaders($this->h)->getJson("/v1/documents/{$doc->id}")->assertOk()->assertJsonPath('data.id', $doc->id);
    $this->withHeaders($this->h)->getJson("/v1/documents/{$doc->id}/events")->assertOk();

    $this->withHeaders(apiKeyHeaders(Tenant::factory()->create(), 'sandbox'))->getJson("/v1/documents/{$doc->id}")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'production'))->getJson("/v1/documents/{$doc->id}")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($this->tenant, 'production'))->getJson('/v1/documents')->assertOk()->assertJsonCount(0, 'data');
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
```

Add rows to `tests/Feature/TenantIsolationSweepTest.php` (`cross_tenant_routes` dataset): `'document show' => [fn (Tenant $t) => Document::factory()->for(Issuer::factory()->for($t)->create())->create(), 'GET', '/v1/documents/{id}']`, `'document events'` (GET `/v1/documents/{id}/events`), `'document submit'` (POST `/v1/documents/{id}/submit`); and to `cross_environment_routes`: `'document show (prod doc, test key)'` with a production-environment issuer+document. Note the sweep creates resources while the intruder is not yet bound — factories need a bound tenant for `BelongsToTenant`: the existing sweep binds via `serviceHeaders`/requests only, so create documents inside the closure after `app(TenantContext::class)->bind($owner, null, Environment::Sandbox)` (mirror how the existing rows create issuers; check the file and follow its pattern — if issuers are created without binding, `IssuerFactory` supplies `tenant_id` and `for($t)` overrides; for `Document`, pass `->for($t)` explicitly is not possible (documents have `tenant_id` from context) so bind the context inside the closure and `clear()` afterwards).

- [ ] **Step 2: Run to verify failure** — FAIL (routes missing).

- [ ] **Step 3: Resource DTOs**

`app/Data/Resources/DocumentTotalsData.php`
```php
<?php

namespace App\Data\Resources;

use App\Models\Document;
use Spatie\LaravelData\Data;

class DocumentTotalsData extends Data
{
    public function __construct(
        public string $subtotal,
        public string $discount_total,
        public string $total_excluding_tax,
        public string $tax_total,
        public string $total_including_tax,
        public string $total_payable,
    ) {}

    public static function fromModel(Document $d): self
    {
        return new self($d->subtotal, $d->discount_total, $d->total_excluding_tax, $d->tax_total, $d->total_including_tax, $d->total_payable);
    }
}
```

`app/Data/Resources/DocumentLineResourceData.php`
```php
<?php

namespace App\Data\Resources;

use App\Models\DocumentLine;
use Spatie\LaravelData\Data;

class DocumentLineResourceData extends Data
{
    /** @param array<string, mixed>|null $metadata */
    public function __construct(
        public int $position,
        public string $classification_code,
        public string $description,
        public string $quantity,
        public string $unit_code,
        public string $unit_price,
        public string $discount_amount,
        public ?string $discount_rate,
        public string $tax_type,
        public ?string $tax_rate,
        public string $tax_amount,
        public ?string $tax_exemption_reason,
        public string $subtotal,
        public string $total,
        public ?array $metadata,
    ) {}

    public static function fromModel(DocumentLine $l): self
    {
        return new self(
            $l->position, $l->classification_code, $l->description, $l->quantity, $l->unit_code, $l->unit_price,
            $l->discount_amount, $l->discount_rate, $l->tax_type, $l->tax_rate, $l->tax_amount, $l->tax_exemption_reason,
            $l->subtotal, $l->total, $l->metadata,
        );
    }
}
```

`app/Data/Resources/DocumentEventData.php`
```php
<?php

namespace App\Data\Resources;

use App\Models\DocumentEvent;
use Spatie\LaravelData\Data;

class DocumentEventData extends Data
{
    /** @param array<string, mixed>|null $meta */
    public function __construct(
        public string $id,
        public ?string $from_status,
        public string $to_status,
        public ?string $reason,
        public ?array $meta,
        public ?string $actor_type,
        public ?string $actor_id,
        public string $created_at,
    ) {}

    public static function fromModel(DocumentEvent $e): self
    {
        return new self($e->id, $e->from_status?->value, $e->to_status->value, $e->reason, $e->meta, $e->actor_type, $e->actor_id, $e->created_at->toIso8601String());
    }
}
```

`app/Data/Resources/DocumentData.php`
```php
<?php

namespace App\Data\Resources;

use App\Models\Document;
use Spatie\LaravelData\Data;

class DocumentData extends Data
{
    /**
     * @param  array<string, mixed>  $buyer
     * @param  list<DocumentLineResourceData>  $lines
     * @param  array{system: string, ref: string}  $source
     * @param  array<string, mixed>|null  $original_document_ref
     * @param  array<string, mixed>|null  $payment
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $lhdn
     */
    public function __construct(
        public string $id,
        public string $issuer_id,
        public ?string $buyer_id,
        public ?string $group_id,
        public string $environment,
        public string $type,
        public string $lhdn_type_code,
        public string $status,
        public ?string $held_reason,
        public array $buyer,
        public string $currency,
        public ?string $exchange_rate,
        public string $issue_date,
        public DocumentTotalsData $totals,
        public array $lines,
        public bool $consolidate,
        public array $source,
        public ?array $original_document_ref,
        public ?array $payment,
        public ?array $metadata,
        public ?array $lhdn,
        public ?string $validated_at,
        public ?string $submitted_at,
        public ?string $cancelled_at,
        public ?string $cancel_reason,
        public ?string $consolidated_into_id,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(Document $d): self
    {
        $d->loadMissing('lines');
        $hasLhdn = $d->lhdn_uuid !== null || $d->lhdn_submission_uid !== null || $d->lhdn_errors !== null;

        return new self(
            id: $d->id,
            issuer_id: $d->issuer_id,
            buyer_id: $d->buyer_id,
            group_id: $d->group_id,
            environment: $d->environment->value,
            type: $d->type->value,
            lhdn_type_code: $d->type->lhdnCode(),
            status: $d->status->value,
            held_reason: $d->held_reason?->value,
            buyer: $d->buyer_snapshot,
            currency: $d->currency,
            exchange_rate: $d->exchange_rate,
            issue_date: $d->issue_date->toDateString(),
            totals: DocumentTotalsData::fromModel($d),
            lines: $d->lines->map(fn ($l) => DocumentLineResourceData::fromModel($l))->all(),
            consolidate: $d->consolidate,
            source: ['system' => $d->source_system, 'ref' => $d->source_ref],
            original_document_ref: $d->original_document_id !== null || $d->original_lhdn_uuid !== null
                ? ['document_id' => $d->original_document_id, 'lhdn_uuid' => $d->original_lhdn_uuid] : null,
            payment: $d->payment,
            metadata: $d->metadata,
            lhdn: $hasLhdn ? [
                'uuid' => $d->lhdn_uuid, 'long_id' => $d->lhdn_long_id, 'submission_uid' => $d->lhdn_submission_uid,
                'errors' => $d->lhdn_errors, 'status_at' => $d->lhdn_status_at?->toIso8601String(),
            ] : null,
            validated_at: $d->validated_at?->toIso8601String(),
            submitted_at: $d->submitted_at?->toIso8601String(),
            cancelled_at: $d->cancelled_at?->toIso8601String(),
            cancel_reason: $d->cancel_reason,
            consolidated_into_id: $d->consolidated_into_id,
            created_at: $d->created_at->toIso8601String(),
            updated_at: $d->updated_at->toIso8601String(),
        );
    }
}
```

`app/Data/Requests/Documents/DocumentFilterData.php`
```php
<?php

namespace App\Data\Requests\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class DocumentFilterData extends Data
{
    public function __construct(
        public ?DocumentStatus $status = null,
        public ?string $issuer_id = null,
        public ?string $group_id = null,
        public ?DocumentType $type = null,
        public ?string $source_system = null,
        public ?string $source_ref = null,
        public ?string $issue_date_from = null,
        public ?string $issue_date_to = null,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(ValidationContext $context): array
    {
        return [
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, DocumentStatus::cases()))],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_map(fn ($c) => $c->value, DocumentType::cases()))],
            'issuer_id' => ['nullable', 'string', 'max:26'],
            'group_id' => ['nullable', 'string', 'max:26'],
            'source_system' => ['nullable', 'string', 'max:50'],
            'source_ref' => ['nullable', 'string', 'max:191'],
            'issue_date_from' => ['nullable', 'date_format:Y-m-d'],
            'issue_date_to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
```
In the controller build it from the query string: `DocumentFilterData::validateAndCreate($request->query())`.

- [ ] **Step 4: Controllers and routes**

`app/Http/Controllers/Api/V1/DocumentController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\SubmitDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Data\Requests\Documents\DocumentFilterData;
use App\Data\Resources\DocumentData;
use App\Data\Resources\DocumentEventData;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\DataCollection;

class DocumentController extends Controller
{
    /** @return CursorPaginatedDataCollection<int, DocumentData> */
    public function index(Request $request): CursorPaginatedDataCollection
    {
        $f = DocumentFilterData::validateAndCreate($request->query());
        $q = Document::forCurrentEnvironment()->with('lines');
        $q->when($f->status, fn ($q) => $q->where('status', $f->status));
        $q->when($f->type, fn ($q) => $q->where('type', $f->type));
        $q->when($f->issuer_id, fn ($q) => $q->where('issuer_id', $f->issuer_id));
        $q->when($f->group_id, fn ($q) => $q->where('group_id', $f->group_id));
        $q->when($f->source_system, fn ($q) => $q->where('source_system', $f->source_system));
        $q->when($f->source_ref, fn ($q) => $q->where('source_ref', $f->source_ref));
        $q->when($f->issue_date_from, fn ($q) => $q->whereDate('issue_date', '>=', $f->issue_date_from));
        $q->when($f->issue_date_to, fn ($q) => $q->whereDate('issue_date', '<=', $f->issue_date_to));

        return DocumentData::collect($q->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50), CursorPaginatedDataCollection::class);
    }

    public function store(CreateDocumentData $data, CreateDocument $create, AuditLogger $audit): JsonResponse
    {
        $result = $create->handle($data);
        $doc = $result->document;
        if ($result->replayed) {
            return response()->json(['data' => DocumentData::fromModel($doc)->toArray()], 200, ['Idempotent-Replay' => 'true']);
        }
        $audit->record('document.created', $doc, [
            'type' => $doc->type->value, 'source' => ['system' => $doc->source_system, 'ref' => $doc->source_ref],
            'total_payable' => $doc->total_payable, 'status' => $doc->status->value,
        ]);

        return response()->json(['data' => DocumentData::fromModel($doc)->toArray()], 201);
    }

    public function show(Document $document): DocumentData
    {
        return DocumentData::fromModel($document)->wrap('data');
    }

    public function submit(Document $document, SubmitDocument $submit, AuditLogger $audit): DocumentData
    {
        $doc = $submit->handle($document);
        $audit->record('document.submitted', $doc, ['status' => $doc->status->value]);

        return DocumentData::fromModel($doc)->wrap('data');
    }

    /** @return DataCollection<int, DocumentEventData> */
    public function events(Document $document): DataCollection
    {
        return DocumentEventData::collect($document->events()->get(), DataCollection::class)->wrap('data');
    }
}
```
(`submit()` returns a `Data` from a `POST`, which spatie answers with 201 — the test expects 200: return `response()->json(['data' => DocumentData::fromModel($doc)->toArray()], 200)` instead and change the return type to `JsonResponse`.)

`app/Http/Controllers/Api/V1/DocumentBatchController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\CreateDocumentBatch;
use App\Data\Requests\Documents\CreateDocumentBatchData;
use App\Data\Resources\DocumentData;
use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;

class DocumentBatchController extends Controller
{
    public function store(CreateDocumentBatchData $batch, CreateDocumentBatch $create, AuditLogger $audit): JsonResponse
    {
        $result = $create->handle($batch);
        $audit->record('document.batch_created', null, ['group_id' => $result['group_id'], 'count' => count($result['documents'])]);

        return response()->json([
            'data' => array_map(fn ($r) => DocumentData::fromModel($r->document)->toArray(), $result['documents']),
            'meta' => ['group_id' => $result['group_id'], 'count' => count($result['documents'])],
        ], 201);
    }
}
```

Routes — inside the `tenant` group in `routes/api.php`:
```php
Route::middleware('ability:read')->group(function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::get('/documents/{document}/events', [DocumentController::class, 'events']);
});
Route::middleware('ability:documents:write')->group(function () {
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('idempotency');
    Route::post('/documents/batch', [DocumentBatchController::class, 'store'])->middleware('idempotency');
    Route::post('/documents/{document}/submit', [DocumentController::class, 'submit']);
});
```
Route order matters: declare `/documents/batch` before `/documents/{document}` routes are matched? Laravel matches in registration order per method; `POST /documents/batch` vs `POST /documents/{document}/submit` don't collide, and `GET /documents/{document}` is GET-only, so the above is fine.

`ProblemResponse::describe()` — add before the generic `HttpExceptionInterface` branch:
```php
if ($e instanceof \App\Domain\Documents\InvalidTransition) {
    return [409, 'Conflict', $e->getMessage(), 'invalid_transition', []];
}
if ($e instanceof \App\Domain\Documents\CancellationWindowClosed) {
    return [409, 'Conflict', $e->getMessage(), 'cancellation_window_closed', []];
}
```

- [ ] **Step 5: Run tests** — `vendor/bin/pest tests/Feature/Documents tests/Feature/TenantIsolationSweepTest.php` → pass; `composer check` green.

- [ ] **Step 6: Commit**

```bash
git add app/Data/Resources/Document*.php app/Data/Requests/Documents/DocumentFilterData.php app/Http/Controllers/Api/V1/DocumentController.php app/Http/Controllers/Api/V1/DocumentBatchController.php app/Http/Problem/ProblemResponse.php routes/api.php tests/Feature/Documents/DocumentEndpointsTest.php tests/Feature/TenantIsolationSweepTest.php
git commit -m "feat(documents): create/batch/get/submit/events endpoints with resource DTOs and isolation sweep rows"
```

---

### Task 10: Release held documents when an issuer activates

**Files:**
- Create: `app/Events/IssuerActivated.php`, `app/Jobs/ReleaseHeldDocuments.php`, `app/Listeners/ReleaseHeldDocumentsOnActivation.php`, `tests/Feature/Documents/HeldReleaseTest.php`
- Modify: `app/Services/Issuers/IssuerActivator.php` (dispatch `IssuerActivated` when the new status is `active`), `app/Providers/AppServiceProvider.php` (register listener via `Event::listen` in `boot()`)

**Interfaces:**
- Consumes: `TenantAwareJob`, `DocumentStateMachine`, `Document`, `HeldReason`, `IssuerActivator::apply()`.
- Produces:
  - `App\Events\IssuerActivated(public readonly Issuer $issuer)`.
  - `App\Jobs\ReleaseHeldDocuments implements ShouldQueue` (uses `TenantAwareJob`; constructor `(public readonly string $issuerId)` calls `captureTenantContext()`); `handle(DocumentStateMachine $sm)`: for each `Document` of that issuer with `status = held` and `held_reason->releasableOnIssuerActivation()` → `transition(queued, 'issuer_activated')`; processes in chunks of 100 by id.
  - Listener `ReleaseHeldDocumentsOnActivation::handle(IssuerActivated $e)`: `ReleaseHeldDocuments::dispatch($e->issuer->id)`.
  - `IssuerActivator::apply()` dispatches `IssuerActivated` after saving when `$next === IssuerStatus::Active`.

- [ ] **Step 1: Write failing tests `tests/Feature/Documents/HeldReleaseTest.php`**

```php
<?php

use App\Enums\DocumentStatus;
use App\Enums\Environment;
use App\Enums\HeldReason;
use App\Enums\IssuerStatus;
use App\Events\IssuerActivated;
use App\Jobs\ReleaseHeldDocuments;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Services\Issuers\IssuerActivator;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->authorized()->create(['certificate_valid_until' => now()->addYear()]);
});

it('dispatches IssuerActivated and queues the release job when an issuer becomes active', function () {
    Queue::fake();
    (new IssuerActivator)->apply($this->issuer);
    expect($this->issuer->status)->toBe(IssuerStatus::Active);
    Queue::assertPushed(ReleaseHeldDocuments::class, fn ($job) => $job->issuerId === $this->issuer->id && $job->tenantId === $this->tenant->id);
});

it('moves releasable held documents to queued and leaves the others alone', function () {
    $a = Document::factory()->for($this->issuer)->held(HeldReason::IssuerNotActive)->create();
    $b = Document::factory()->for($this->issuer)->held(HeldReason::CertificateExpired)->create();
    $c = Document::factory()->for($this->issuer)->held(HeldReason::EinvoiceNotRequired)->create();
    $other = Document::factory()->for(Issuer::factory()->for($this->tenant)->create())->held(HeldReason::IssuerNotActive)->create();

    $job = new ReleaseHeldDocuments($this->issuer->id);
    app(TenantContext::class)->clear();
    dispatch_sync($job);

    expect($a->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($b->refresh()->status)->toBe(DocumentStatus::Queued)
        ->and($c->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($other->refresh()->status)->toBe(DocumentStatus::Held)
        ->and($a->events()->latest('created_at')->first()->reason)->toBe('issuer_activated');
});

it('runs end-to-end through the real listener when the queue is sync', function () {
    Event::fake([IssuerActivated::class]);
    (new IssuerActivator)->apply($this->issuer);
    Event::assertDispatched(IssuerActivated::class);
});
```

- [ ] **Step 2: Run to verify failure** — FAIL.

- [ ] **Step 3: Implement**

`app/Events/IssuerActivated.php`
```php
<?php

namespace App\Events;

use App\Models\Issuer;
use Illuminate\Foundation\Events\Dispatchable;

class IssuerActivated
{
    use Dispatchable;

    public function __construct(public readonly Issuer $issuer) {}
}
```

`app/Jobs/ReleaseHeldDocuments.php`
```php
<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ReleaseHeldDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public function __construct(public readonly string $issuerId)
    {
        $this->captureTenantContext();
    }

    public function handle(DocumentStateMachine $stateMachine): void
    {
        $releasable = array_map(fn (HeldReason $r) => $r->value, array_filter(HeldReason::cases(), fn (HeldReason $r) => $r->releasableOnIssuerActivation()));

        Document::query()
            ->where('issuer_id', $this->issuerId)
            ->where('status', DocumentStatus::Held)
            ->whereIn('held_reason', $releasable)
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($stateMachine): void {
                foreach ($documents as $document) {
                    $stateMachine->transition($document, DocumentStatus::Queued, 'issuer_activated');
                }
            });
    }
}
```

`app/Listeners/ReleaseHeldDocumentsOnActivation.php`
```php
<?php

namespace App\Listeners;

use App\Events\IssuerActivated;
use App\Jobs\ReleaseHeldDocuments;

class ReleaseHeldDocumentsOnActivation
{
    public function handle(IssuerActivated $event): void
    {
        ReleaseHeldDocuments::dispatch($event->issuer->id);
    }
}
```

`IssuerActivator::apply()` — after `$issuer->save();` add:
```php
if ($next === IssuerStatus::Active) {
    IssuerActivated::dispatch($issuer);
}
```
`AppServiceProvider::boot()` — add `Event::listen(IssuerActivated::class, ReleaseHeldDocumentsOnActivation::class);` (Laravel 12 also auto-discovers listeners in `app/Listeners`, but registering explicitly keeps it deterministic).

- [ ] **Step 4: Run tests** — `vendor/bin/pest tests/Feature/Documents/HeldReleaseTest.php tests/Feature/IssuerSecretsTest.php` (certificate upload activates issuers → the listener now dispatches; with `QUEUE_CONNECTION=sync` the job runs inline in those tests — they must still pass) → pass; `composer check` green.

- [ ] **Step 5: Commit**

```bash
git add app/Events/IssuerActivated.php app/Jobs/ReleaseHeldDocuments.php app/Listeners/ReleaseHeldDocumentsOnActivation.php app/Services/Issuers/IssuerActivator.php app/Providers/AppServiceProvider.php tests/Feature/Documents/HeldReleaseTest.php
git commit -m "feat(documents): release held documents when an issuer activates (tenant-aware job)"
```

---

## Plan self-review (done at authoring time)

- **Spec coverage:** §3.1 job re-binding → Task 1; §5.1 DTO → Task 3 (line `subtotal/total` made optional-but-checked; document `totals.total_payable` optional-but-checked — recorded decision); §5.2 storage → Task 5 (all listed columns incl. LHDN fields for Plan 3, `environment` added for scoping); §5.3 state machine + held reasons + 72h window → Task 6 (`held → queued` release; `queued → held` for Plan 3; `awaiting_consolidation → queued` allowed for later un-consolidation); §5.4 idempotency (natural key replay/409 + `Idempotency-Key`) → Tasks 7–9; §5.5 batch all-or-nothing + `group_id` filter → Tasks 7, 9; §8 documents rows (create, batch, list w/ filters, show, submit, events; `cancel` and `pdf` are Plans 3/4) → Task 9; §9 error mapping → Tasks 8, 9; held-release on activation (Plan 1 backlog / roadmap) → Task 10.
- **Placeholders:** none. Every code block is complete; the only "adapt if" notes concern spatie's nested-collection error-key naming (Task 3 Step 4) and the 200-vs-201 status for `submit` (Task 9 Step 4), both with the concrete alternative spelled out.
- **Type consistency:** `CreateDocumentData::payloadHash()` used by Task 7; `DocumentCreated(document, replayed)` used by Tasks 7, 9; `TotalsCalculator::calculate(iterable, ?DocumentTotalsInputData): DocumentTotals` with `toStrings()` and `lines[]` used by Task 7; `DocumentStateMachine::transition(Document, DocumentStatus, ?string, array): DocumentEvent` used by Tasks 7, 10; `HeldReason::releasableOnIssuerActivation()` used by Task 10; `TenantAwareJob::captureTenantContext()` + `middleware()` used by Task 10; `Document::forCurrentEnvironment()` used by Tasks 7, 9; `IssuerActivator::apply()` (Plan 1) modified in Task 10.
