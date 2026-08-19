# Billplz E-Invoice Engine — Design Spec

**Date:** 2026-08-19
**Status:** Approved for planning
**Scope (v1):** Standalone Laravel service exposing a REST API. No merchant portal, no SDK (SDK and dashboard integration are follow-up projects).

## 1. Purpose

A single e-invoicing engine that every Billplz product (Catalog, Recurring, Affiliates, Billplz core) and external merchants use to issue, sign, submit, track and cancel LHDN MyInvois e-invoices. It centralises MyInvois credentials, token caching, rate-limit budgeting, batching, retries, reference data and audit so that consuming products only describe *what* was sold and to whom.

### Goals
- Multi-tenant: many Billplz accounts, strict row-level isolation.
- Multi-issuer per tenant: a marketplace Catalog store with N vendors is one tenant with N+1 issuers.
- API-first: identical API for internal products and external merchants.
- LHDN access in two modes per issuer: Billplz-as-intermediary, or the issuer's own MyInvois credentials.
- Per-issuer digital signing certificates.
- Resilient: order/subscription flows never block on LHDN availability or vendor onboarding state.

### Non-goals (v1)
- Merchant-facing UI (will live in the existing Billplz dashboard later).
- PHP SDK package (follow-up; API is designed so the SDK is a thin client).
- Accounting/ledger features, payment collection.
- Peppol / cross-border formats beyond LHDN MyInvois UBL 2.1.

## 2. Architecture overview

```
Catalog ─┐                         ┌────────────────────────────────┐
Recurring├─ service token ─────────▶│        E-Invoice Engine        │──▶ LHDN MyInvois API
Affiliates┘  (X-Tenant-Id)         │  Laravel 12 · MySQL · Redis     │◀── (sandbox / production)
Merchants ── API key ─────────────▶│  Horizon queues · Webhooks      │
                                   └───────────────┬────────────────┘
                                                   └──▶ Webhooks back to consumers
```

Layers inside the engine:

| Layer | Responsibility |
|---|---|
| HTTP / API (`app/Http`) | Auth, tenant resolution, validation, RFC 7807 errors, versioned `/v1` |
| Application (`app/Actions`, `app/Services`) | Use-cases: create document, submit, cancel, verify TIN, onboard issuer |
| Domain (`app/Domain`) | Document DTOs, state machine, UBL builder, signer, consolidation rules |
| Infrastructure (`app/Lhdn`, `app/Webhooks`, `app/Tenancy`) | LHDN clients, token cache, batcher, poller, webhook delivery, tenancy scope |

Each unit answers: what it does, how it is called, what it depends on. Domain code has no HTTP or Eloquent-query dependencies beyond models passed in.

## 3. Tenancy & authentication

### 3.1 Tenant model
- `Tenant` = one Billplz account/organisation.
- Single database, row-level tenancy. Every tenant-owned table has `tenant_id` (indexed, part of unique constraints).
- `BelongsToTenant` trait: global scope `where tenant_id = current`, auto-fills `tenant_id` on create, throws if no tenant context is bound when creating.
- `TenantContext` singleton is bound by middleware per request and by jobs (jobs carry `tenant_id` and re-bind it on handle).
- Cross-tenant access is prevented by construction; a test asserts every `/v1` endpoint returns 404 for another tenant's resource ids.

### 3.2 Credentials
Two credential types, both resolving to `TenantContext { tenant, actor, environment }`:

| Type | Used by | Shape | Notes |
|---|---|---|---|
| API key | External merchants, merchant-side integrations | `ek_live_<random>` / `ek_test_<random>`, stored as SHA-256 hash, prefix shown | Environment-bound; abilities: `read`, `documents:write`, `issuers:manage`, `webhooks:manage` |
| Service token | Catalog, Recurring, Affiliates, Billplz core | Bearer token per service, stored hashed | Must send `X-Tenant-Id`. Actor recorded as `service:catalog` etc. May act for any tenant; may create tenants (`tenants:manage`) |

Environment rule: a `test` key can only touch issuers with `environment = sandbox`; `live` only `production`. Service tokens choose via `X-Environment` header (default `production`).

### 3.3 Rate limiting
Per credential (Redis, `throttle:api`) plus per-issuer LHDN budget (see 6.4).

## 4. Issuers, secrets, buyers

### 4.1 Issuer
Fields: `tenant_id`, `name`, `tin`, `id_type` (BRN | NRIC | PASSPORT | ARMY), `id_number`, `sst_number?`, `tourism_tax_number?`, `msic_code`, `business_activity_description`, address (line1-3, postcode, city, state code, country), `email`, `phone`, `environment` (sandbox | production), `lhdn_mode` (intermediary | own_credentials), `einvoice_required` (bool; false = below LHDN threshold, documents are stored but never submitted), `consolidation_enabled` (bool), `status`, `activated_at`.

Status flow: `draft -> tin_verified -> authorized -> active`, plus `suspended` (manual/cert expired). `authorized` means: intermediary mode -> merchant confirmed intermediary consent in MyInvois and a test token fetch succeeded; own_credentials -> credentials stored and token fetch succeeded. `active` additionally requires a valid signing certificate.

Uniqueness: `(tenant_id, tin, environment)`.

### 4.2 Issuer secrets
Table `issuer_secrets` (1:1 with issuer), all sensitive columns use Laravel `encrypted` casts (APP_KEY; rotation via `APP_PREVIOUS_KEYS`):
- `lhdn_client_id`, `lhdn_client_secret` (own_credentials mode only)
- `signing_certificate` (PEM/PKCS#12 bytes, base64), `signing_key`, `signing_key_passphrase`
- Public metadata (unencrypted): `cert_subject`, `cert_serial`, `cert_fingerprint`, `cert_not_before`, `cert_not_after`, `credentials_verified_at`.

API never returns secret material; only `has_credentials`, `has_certificate`, cert metadata. Upload replaces atomically; previous cert kept in `issuer_secret_history` (encrypted) for audit of already-signed documents.

### 4.3 Buyers
Per-tenant `buyers` registry: `name`, `tin?`, `id_type/id_number?`, `sst_number?`, `email`, `phone`, address, `tin_validated_at`, `tin_validation_result`. Documents may reference `buyer_id` or embed buyer data inline; a `general_public` buyer flag marks B2C for consolidation.

### 4.4 TIN validation
`POST /v1/tin/validate {tin, id_type, id_number}` calls LHDN validate-TIN through the acting issuer's client (or Billplz's own intermediary client if `issuer_id` omitted). Results cached in Redis 24h keyed by tenant + tin + id.

## 5. Documents

### 5.1 Canonical DTO
`DocumentData` (spatie/laravel-data):
- `type`: `invoice | credit_note | debit_note | refund_note | self_billed_invoice | self_billed_credit_note | self_billed_debit_note | self_billed_refund_note`
- `issuer_id`
- `buyer`: `{buyer_id}` | inline buyer | `{general_public: true}`
- `currency` (ISO 4217), `exchange_rate?` (required if not MYR)
- `issue_date?` (default now, Asia/Kuala_Lumpur)
- `lines[]`: `classification_code`, `description`, `quantity`, `unit_code` (UOM), `unit_price`, `discount_amount?`, `discount_rate?`, `tax_type`, `tax_rate?`, `tax_amount?`, `tax_exemption_reason?`, `subtotal`, `total`, `metadata?`
- `totals`: computed by engine; caller-supplied totals are validated to match within tolerance 0.01.
- `original_document_ref?`: `{document_id | lhdn_uuid}` — required for notes.
- `payment?`: mode, terms, `paid_at?`, `payment_ref?`
- `consolidate` (bool, default false; only valid when buyer is general public and issuer has consolidation enabled)
- `source`: `{system, ref}` — required, used for idempotency and tracing
- `metadata` (free JSON, max 8 KB)
- `group_id?`: set by batch endpoint

### 5.2 Storage
`documents` (tenant_id, issuer_id, group_id, type, status, buyer snapshot JSON, currency, totals, source_system, source_ref, lhdn_uuid, lhdn_long_id, lhdn_submission_uid, validated_at, submitted_at, lhdn_status_at, cancelled_at, cancel_reason, held_reason, consolidated_into_id, ubl_json (longtext), signed_payload_hash, pdf_path, metadata), `document_lines`, `document_events` (transition log), `submission_attempts` (request/response, http status, error codes, duration).

Unique: `(tenant_id, source_system, source_ref, type)`.

### 5.3 State machine
```
draft --validate--> validated --queue--> queued --batch--> submitted --poll--> valid
                        |                                                 \--> invalid
                        \--hold--> held --release--> queued
valid --cancel (<=72h)--> cancelled        valid --buyer rejects--> rejected
consolidate=true documents: validated --> awaiting_consolidation --> consolidated
```
- `held` reasons: `issuer_not_active`, `certificate_expired`, `lhdn_credentials_invalid`, `lhdn_unavailable`, `einvoice_not_required` (stays stored, never submitted).
- Transitions implemented in `DocumentStateMachine` (pure PHP), throwing `InvalidTransition`. Every transition writes `document_events` and dispatches a Laravel event consumed by the webhook dispatcher.
- Cancellation window enforced against `lhdn_status_at` (validation time) + 72h; after that the API returns 409 with guidance to issue a credit/refund note.

### 5.4 Idempotency
- Natural key `(tenant, source.system, source.ref, type)`: a repeat `POST /v1/documents` returns the existing document with `200` and header `Idempotent-Replay: true`, unless the payload differs materially (hash mismatch) -> 409.
- Optional `Idempotency-Key` header stored 24h in Redis with the response body; identical key returns identical response.

### 5.5 Batch (multi-vendor)
`POST /v1/documents/batch {documents: [DocumentData...]}` (max 100): all documents validated first; any schema failure fails the whole batch (422 with per-index errors); on success each document is created independently with a shared `group_id`. Submission and lifecycle are per document. `GET /v1/documents?group_id=` lists the group.

### 5.6 Consolidation
Scheduled job (daily, acts on the previous month for issuers with `consolidation_enabled`, target completion by the 7th): groups `awaiting_consolidation` documents per issuer x currency x month into consolidated invoices (buyer = General Public, TIN `EI00000000010`), one line per classification code with description listing the source ref range, submits them via the normal pipeline, and links children (`consolidated_into_id`). Children move to `consolidated`; if the parent goes `invalid`, children return to `awaiting_consolidation` and an alert webhook fires.

## 6. LHDN gateway

### 6.1 Clients
`LhdnClient` interface: `token(Issuer): AccessToken`, `submitDocuments(Issuer, SubmissionBatch): SubmissionResult`, `getSubmission(...)`, `getDocument(...)`, `cancelDocument(...)`, `validateTin(...)`, `searchTin(...)`.
- `IntermediaryLhdnClient`: Billplz client credentials from config; token requested with `onbehalfof: <issuer TIN>`.
- `OwnCredentialsLhdnClient`: issuer's encrypted credentials.
- `FakeLhdnClient`: deterministic responses for tests, driven by fixture files.
Resolution: `LhdnClientFactory::for(Issuer)` by `lhdn_mode` and `environment` (base URLs per environment in config).

### 6.2 Token cache
Redis key `lhdn:token:{env}:{mode}:{issuer_id}` with TTL = expires_in - 60s. Single-flight lock to avoid stampedes.

### 6.3 Submission pipeline
Per document (queued job `PrepareDocument`): `BuildUbl` (UBL 2.1 JSON per LHDN SDK schema, version 1.1 signed) -> `HashDocument` (SHA-256 of canonical JSON) -> `SignDocument` (XAdES-style signature per LHDN signing spec using issuer cert; implemented in `Signer` with phpseclib/OpenSSL) -> status `queued`.
`SubmissionBatcher` job (every 10s per issuer with queued docs, or when 100 reached / 5 MB): drains up to 100 documents / max 5 MB, one `submitDocuments` call, records `submission_attempts`, sets `submitted` + `lhdn_submission_uid`. Accepted/rejected documents in the response are handled individually.
`SubmissionPoller` job: polls `getSubmission` with backoff (5s, 15s, 30s, 60s... up to 1h) until every document is `valid` or `invalid`; stores `lhdn_uuid`, `long_id`, validation errors.

### 6.4 Rate limiting & resilience
- Per-issuer/token limiter honouring LHDN documented limits (config-driven; e.g. submit max 100/min, token max 12/min). Jobs release back to the queue when the budget is exhausted.
- Transient errors (429, 5xx, network) -> retry with exponential backoff, max 8 attempts, then `held` with reason `lhdn_unavailable` and an ops alert. Terminal errors (400 validation) -> `invalid` with mapped errors.
- Circuit breaker per environment: after N consecutive 5xx, pause batching for 60s.

### 6.5 Cancellation & rejection
`POST /v1/documents/{id}/cancel {reason}` -> LHDN cancel within 72h of validation -> `cancelled`. Buyer rejections detected by poller/notification -> `rejected`; issuer must cancel or ignore per LHDN rules.

## 7. Supporting services

### 7.1 Reference data
Tables: `ref_msic_codes`, `ref_classification_codes`, `ref_tax_types`, `ref_unit_types`, `ref_currencies`, `ref_state_codes`, `ref_country_codes`, `ref_payment_modes`, `ref_document_types`. Seeded from LHDN SDK JSON; `einvoice:refresh-reference-data` artisan command; version column; read via `GET /v1/reference/{set}` (cached, ETag).

### 7.2 Webhooks
`webhook_endpoints` per tenant (url, secret, events[], enabled, environment). Delivery via spatie/laravel-webhook-server: HMAC-SHA256 signature header, retries with backoff (up to 24h), `webhook_deliveries` log, manual redelivery endpoint. Events: `document.validated`, `document.held`, `document.queued`, `document.submitted`, `document.valid`, `document.invalid`, `document.cancelled`, `document.rejected`, `document.consolidated`, `issuer.status_changed`, `certificate.expiring`, `certificate.expired`.

### 7.3 PDF & QR
`GET /v1/documents/{id}/pdf` renders a visual invoice (Blade + dompdf) with LHDN validation link QR (`{portal}/{uuid}/share/{long_id}`) once `valid`; cached to storage; regenerated on cancel to show status.

### 7.4 Certificate lifecycle
Daily job checks `cert_not_after`; emits `certificate.expiring` at 30/7/1 days, `certificate.expired` and moves issuer to `suspended` (documents -> `held`, reason `certificate_expired`) on expiry. Uploading a new cert re-activates and releases held documents.

### 7.5 Audit
`audit_logs`: tenant, actor, action, subject, ip, request id, diff (for issuers/secrets metadata/webhooks/keys). `submission_attempts` keep full LHDN exchanges. Retention: 7 years (LHDN requirement) — no automatic pruning of documents.

## 8. API (v1)

Base: `/v1`, JSON, `Authorization: Bearer <api key | service token>`, `X-Tenant-Id` (service tokens), `X-Environment` (service tokens), `Idempotency-Key` (optional on POST). Pagination: cursor-based (`?cursor=&limit=`). Errors: `application/problem+json` `{type,title,status,detail,errors:[{pointer,code,message}]}`.

| Method | Path | Ability | Notes |
|---|---|---|---|
| POST | /tenants | tenants:manage (service only) | create tenant |
| POST/GET/DELETE | /api-keys, /api-keys/{id} | issuers:manage | key shown once |
| POST/GET/PATCH | /issuers, /issuers/{id} | issuers:manage | |
| POST | /issuers/{id}/verify-tin | issuers:manage | -> tin_verified |
| PUT | /issuers/{id}/credentials | issuers:manage | own_credentials mode |
| PUT | /issuers/{id}/certificate | issuers:manage | multipart or base64 |
| POST | /issuers/{id}/authorize | issuers:manage | tests token fetch -> authorized/active |
| POST/GET/PATCH | /buyers, /buyers/{id} | documents:write | |
| POST | /tin/validate | read | |
| POST | /documents | documents:write | create + validate + auto-submit (`submit: true` default) |
| POST | /documents/batch | documents:write | max 100 |
| GET | /documents, /documents/{id} | read | filters: status, issuer_id, group_id, type, source_ref, date range |
| POST | /documents/{id}/submit | documents:write | for `draft`/`held` |
| POST | /documents/{id}/cancel | documents:write | |
| GET | /documents/{id}/pdf | read | |
| GET | /documents/{id}/events | read | |
| POST/GET/PATCH/DELETE | /webhooks, /webhooks/{id} | webhooks:manage | |
| POST | /webhooks/{id}/test, /webhook-deliveries/{id}/redeliver | webhooks:manage | |
| GET | /reference/{set} | read | |
| GET | /health | none | |

Versioning: URL prefix; breaking changes -> `/v2`.

## 9. Error handling summary
- Validation: 422 problem+json with JSON-pointer `errors[]`.
- Not found across tenants: 404 (never 403, to avoid existence leaks).
- Idempotency conflict: 409.
- Cancellation window passed / invalid transition: 409 with `code`.
- LHDN validation failures: document `invalid`, errors stored on document (`lhdn_errors[]` with LHDN codes) and returned in `GET`.
- LHDN outage: documents `held`/retried; API remains available.

## 10. Testing strategy
- **Unit (Pest):** UBL builder vs LHDN sample payload fixtures (golden files); Signer round-trip with a generated test cert; state machine transitions (table-driven); totals/tax computation; consolidation grouping.
- **Feature:** every endpoint with `FakeLhdnClient`; tenancy isolation suite (for each route, tenant B cannot see tenant A resources); idempotency; batch; environment key/issuer mismatch.
- **Integration (opt-in, `LHDN_SANDBOX_TESTS=1`):** real sandbox token, submit, poll, cancel.
- **Static:** PHPStan level 8 (Larastan), Pint.
- CI: GitHub Actions running the above on MySQL 8 + Redis services.

## 11. Stack
Laravel 12 · PHP 8.3 · MySQL 8 · Redis · Horizon · Pest · spatie/laravel-data · spatie/laravel-webhook-server · phpseclib (signing) · dompdf (PDF) · endroid/qr-code · Larastan · Pint. Repo: this folder (`billplz/einvoice-engine`), single app.

## 12. Decisions log
| Decision | Choice | Why |
|---|---|---|
| Deployment shape | Standalone service + API | one LHDN integration point, independent releases |
| Tenancy | single DB, row-level | scale to many merchants cheaply; simple ops |
| Tenant vs issuer | separate, 1:N | marketplace vendors, self-billing, Billplz itself as issuer |
| LHDN access | intermediary **and** own credentials, per issuer | user decision; flexibility for merchants already registered |
| Signing | per-issuer certificates | user decision; legal separation |
| Marketplace seller | configurable per Catalog store; engine agnostic | user decision; engine only needs `issuer_id` per document |
| Secrets | encrypted DB columns | user decision; no extra infra in v1 |
| v1 scope | API only | user decision; SDK + portal later |

## 13. Follow-up projects (out of scope here)
1. `billplz/einvoice-sdk` PHP package (thin client + Laravel service provider).
2. Onboarding dashboard (UI/UX) — self-service merchant onboarding wizard (issuer profile, TIN verification, LHDN access mode, consent/credentials, certificate upload, sandbox test, go-live), marketplace vendor onboarding, API keys, document browser, webhooks. Planned as Plan 5 in the roadmap; needs its own design pass (hosting inside the engine vs. the Billplz dashboard, framework, flows).
3. Catalog integration (store seller-mode setting, order -> batch mapping).
4. Recurring integration (cycle -> invoice; consolidation defaults).
5. Affiliates integration (payout -> self-billed invoice).
