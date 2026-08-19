# E-Invoice Engine — Implementation Roadmap

**Spec:** `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md`

The spec is one service but four subsystems. Each plan below produces working, testable software on its own and is executed in order. Only Plan 1 is written in full so far; each subsequent plan is written (with the writing-plans skill) once the previous one is merged, so it can build on the real code.

| # | Plan | Spec sections | Deliverable |
|---|------|---------------|-------------|
| 1 | `2026-08-19-plan-1-foundation.md` | 2, 3, 4, 7.1, 7.5, 8 (tenants, api-keys, issuers, buyers, reference), 9, 10, 11 | Running API with tenancy, auth, issuers + secrets/certs, buyers, reference data, audit, problem+json errors, isolation test suite |
| 2 | `2026-08-19-plan-2-documents-core.md` — Documents core | 5.1–5.5, 8 (documents create/batch/get/events), 9 | `DocumentData` DTO, validation & totals, `documents`/`document_lines`/`document_events` tables, `DocumentStateMachine`, create/batch/get/events endpoints, idempotency (natural key + `Idempotency-Key`), `held` logic for inactive issuers, domain events (no LHDN yet — documents stop at `queued`) |
| 3 | `2026-08-19-plan-3-lhdn-gateway.md` — LHDN gateway | 4.4, 6.1–6.5, 8 (verify-tin, authorize, tin/validate, submit, cancel) | `LhdnClient` interface + Intermediary/OwnCredentials/Fake clients, token cache, UBL 2.1 builder (golden-file tests), Signer, `PrepareDocument`/`SubmissionBatcher`/`SubmissionPoller` jobs, rate limiter + circuit breaker, cancel & rejection, issuer authorize flow, opt-in sandbox integration tests |
| 4 | Plan 4 — Consolidation, webhooks, PDF, cert lifecycle | 5.6, 7.2, 7.3, 7.4, 8 (webhooks, pdf, redeliver) | Monthly consolidation job, webhook endpoints + signed delivery + redeliver, PDF/QR rendering, cert expiry monitor + suspension/release, ops alerts |
| 5 | Plan 5 — Onboarding dashboard (UI/UX) | 13.2 (added 2026-08-19 at user request) | Merchant-facing web UI for self-service onboarding: sign-in via Billplz account, issuer wizard (business profile → TIN verify → LHDN access mode → intermediary consent / own credentials → certificate upload → sandbox test → go live), vendor onboarding for marketplaces (invite + progress tracking), API key management, document browser with status/errors, webhook setup. Front end: **Inertia.js + React** (user decision 2026-08-19), served by the engine app. Gets its own brainstorm + spec before planning (auth/SSO with Billplz accounts, UX flows, empty/error states, design system). |

Follow-ups outside this repo (spec §13): SDK, portal, Catalog/Recurring/Affiliates integrations.

## Plan 1 outcome (2026-08-19)

Branch `plan-1-foundation`: 11 tasks + final fix wave; 91 Pest tests / 256 assertions, PHPStan level 8, Pint clean; no hosted CI (removed at user request; `composer check` is the gate). Reviewed task-by-task and whole-branch (see the SDD ledger for rulings).

### Backlog carried into Plan 2+ (deferred minors from reviews)
- **First task of Plan 2:** tenant context for queued jobs — `TenantAware` job trait / base job that re-binds `TenantContext` in `handle()` (spec §3.1); `AuditLogger` should take an explicit context object rather than injecting `Request` (jobs/console).
- `/v1/health` shares the unauthenticated IP throttle bucket — exempt it or point infra probes at `/up`.
- `AuditLogger::diff()` — make `$original` a required parameter once a second caller exists; empty `X-Request-Id` header is stored as `''`.
- Reference data: ETag should hash the payload (not only version+count); importer should prune codes withdrawn from LHDN lists; replace starter sets (unit_types, currencies, country_codes, msic_codes) with full LHDN SDK lists before go-live (Plan 3).
- `ProblemResponse::describe()` → small readonly DTO; header-merge precedence comment; 401 `WWW-Authenticate` header.
- `resources/views/welcome.blade.php` unreferenced (delete); `tests/Fixtures/certs/generate.sh` for reproducible fixture rotation; PHPStan paths to include `database/`.
- `PutIssuerCertificateData`: allow empty PKCS#12 passphrase; add `Max` on PEM/base64 fields; distinct `pkcs12_not_base64` code.

## Plan 2 outcome (2026-08-19)

Branch `plan-2-documents-core`: 10 tasks + final fix wave; 183 Pest tests / 558 assertions, PHPStan level 8, Pint clean, `failOnDeprecation` on. Spec amended: natural idempotency key is `(tenant_id, environment, source_system, source_ref, type)`; `Idempotency-Key` cache is per tenant+environment; event listeners are registered explicitly (auto-discovery off).

### Backlog carried into Plan 3+ (deferred minors from reviews)
- **Plan 3 first tasks:** `DocumentStateMachine::transition()` should take a typed `?HeldReason` (currently `HeldReason::from($reason)` can `ValueError`); add `held → held` re-hold (new reason after LHDN outage); `submission_attempts` storage; `ubl_json` / `signed_payload_hash` / `pdf_path` columns (spec §5.2); reuse `TenantAwareJob` (+ `Queueable`) for submission jobs.
- Plan 4: `DocumentTransitioned` is already after-commit; add index on `consolidated_into_id` (and `original_document_id`) with the consolidation query.
- Lists ignore `?limit=` (spec §8) — one shared fix across all cursor-paginated endpoints.
- Batch: per-document audit rows (currently one `document.batch_created`); pre-flight duplicate natural-key check within a batch; natural-key conflicts surface as 409 mid-transaction (documented all-or-nothing).
- `DocumentPaymentData.mode` validated by regex rather than the `payment_modes` reference set.
- `Idempotency-Key`: no concurrency lock (DB natural key keeps outcomes correct); empty-string header passes through.
- `CreateDocument` resolves the issuer before the natural-key replay (replay 404s if issuer removed).
- Tests: top-level non-MYR `exchange_rate` enforcement; mixed-currency batch.
- Plan doc `2026-08-19-plan-2-documents-core.md` still shows the pre-amendment natural key (historical).

## Plan 3 outcome (2026-08-20)

Branch `plan-3-lhdn-gateway`: 9 tasks + final fix wave; 246 Pest tests / 900 assertions (+3 opt-in sandbox tests skipped), PHPStan level 8, Pint clean, `failOnDeprecation` on, `LHDN_DRIVER=fake` in tests.

Shipped: `LhdnClient` interface with `HttpLhdnClient` (intermediary **and** own-credentials via `CredentialsResolver`) + `FakeLhdnClient`; Redis token cache with single-flight (`TokenProvider`); UBL 2.1 builder with golden-file tests and the XAdES-style `DocumentSigner`; the `PrepareDocument` → `SubmitDocuments` → `PollSubmission` pipeline (batching by count and wire bytes, retry/backoff curves in config, `held` reasons for everything the issuer must fix); per-issuer/per-operation rate limiter and per-environment circuit breaker; `submission_attempts` audit trail for every request/response; issuer `verify-tin` / `authorize` onboarding endpoints; document `submit` (resubmit) and `cancel` (72h window); the `einvoice:lhdn-dispatch` safety-net sweep; `docs/lhdn-gateway.md`; opt-in real-sandbox integration tests.

Spec amended: §6.1 (single `HttpLhdnClient` for both modes, `searchTin` deferred), §6.2 (token cache key is `lhdn:token:{env}:{mode}:{sha1(client_id|onbehalfof)}`). Not amended: §7.4 — the certificate-expiry monitor is still the spec; today suspension is only evaluated lazily (see docs/lhdn-gateway.md §6) until Plan 4 builds the monitor.

Final-fix-wave rulings worth remembering: a failed `getSubmission` read is never a verdict on the documents (reschedule, then per-document `getDocument` at the end of the curve); `SubmitDocuments` only fails a whole batch on HTTP 400/422; the circuit breaker counts only connection errors and 5xx (an LHDN 429 is a per-taxpayer limit); limiter/breaker rejections write no `submission_attempts` row in either the token path or the API path.

### Backlog carried into Plan 4+
- **Status-refresh job for `valid` documents** — buyer rejection (`valid -> rejected`) and portal-side cancellation are currently only seen if a poll happens to run after LHDN changed the status, which normally never happens (spec §6.5 partially implemented). This is the first Plan 4 task.
- **Webhooks on `DocumentTransitioned`** — after the refresh path exists, so subscribers see post-`valid` changes too (spec §7.2).
- **Duplicate `codeNumber` on resubmit after a crash** — a document submitted but not recorded will be rejected as a duplicate on resubmit; map that rejection to a `getDocument` lookup and adopt the existing UUID instead of marking it `invalid`.
- **Certificate expiry monitor + automatic suspension/release** (spec §7.4).
- `submission_attempts` retention/pruning (7-year LHDN requirement vs. table growth); the `created_at` index added in Plan 3 exists for that sweep.
- `pdf_path` column has no producer or consumer yet (spec §7.3).
- Consolidation (`awaiting_consolidation -> consolidated`, spec §5.6).
- UBL: `TaxSubtotal` should group by exemption reason; buyer `TTX` (tourism tax) identifier is not emitted; supplier contact `NA` defaults now exist but the issuer schema still requires phone/email.
- `validateTin`: LHDN can answer 400 (malformed TIN) as well as 404; both should surface as `{valid: false}` rather than a terminal exception.
- Honour `Retry-After` from LHDN 429/503 responses instead of the fixed backoff curve.
- `TokenProvider` lock TTL (10s) is unrelated to the HTTP timeout (30s) and `LockTimeoutException` is not mapped to an `LhdnException`.
- `CircuitBreaker` uses a non-atomic `Cache::increment` + `put` for the first failure (a lost race can extend the counter window; harmless but worth a note or a Lua script on Redis).
- `ValidateTin` caches before resolving the issuer, so the cache key does not distinguish the resolved environment in every path.
- `LhdnDriverGuard` covers `LHDN_DRIVER=fake` in production; consider the same guard for missing intermediary credentials.
- Spec §6.1/§6.2 wording drift was corrected in Plan 3; check §6.3–§6.5 against the built pipeline when Plan 4 starts.
