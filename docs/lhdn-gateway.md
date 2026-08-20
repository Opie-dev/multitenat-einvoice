# LHDN gateway

How documents get from `POST /v1/documents` to a validated MyInvois e-invoice
(or a well-explained `held`/`invalid`), and how to operate and test the
pipeline that does it. This is the operational companion to
`docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` §5–§6, which
remains the binding design reference — this doc describes the code as built,
not aspirationally.

## 1. Overview & states

```
draft --validate--> validated --queue--> queued --batch--> submitted --poll--> valid
                        |          \--reject at submission--> invalid          \--> invalid
                        \--hold--> held --release--> queued
                                    held --re-hold--> held
valid --cancel (<=72h)--> cancelled        valid --buyer rejects--> rejected
consolidate=true documents: validated --> awaiting_consolidation --> consolidated
```

All transitions go through `App\Domain\Documents\DocumentStateMachine`, which
validates the move against `DocumentStateMachine::TRANSITIONS`, writes a
`document_events` row, and dispatches `App\Events\DocumentTransitioned`. Two
listeners react to that event (registered explicitly in
`AppServiceProvider::boot()`, since `bootstrap/app.php` disables event
auto-discovery):

- `PrepareDocumentOnQueued` — any document reaching `queued` dispatches
  `App\Jobs\PrepareDocument`. This is the fast path into the pipeline.
- `ReleaseHeldDocumentsOnActivation` — an issuer reaching `active` (via
  `App\Events\IssuerActivated`) dispatches `App\Jobs\ReleaseHeldDocuments`,
  which re-queues everything held for a releasable reason (`issuer_not_active`,
  `certificate_expired`).

`held` reasons (`App\Enums\HeldReason`): `issuer_not_active`,
`certificate_expired`, `lhdn_credentials_invalid`, `lhdn_unavailable`,
`einvoice_not_required`. Only the first two are auto-released on issuer
activation; `lhdn_credentials_invalid` and `lhdn_unavailable` need a manual
`POST /v1/documents/{id}/submit` (or the next scheduled sweep, for
`lhdn_unavailable`) once the underlying problem is fixed.

`queued -> invalid` covers documents LHDN rejects at submission time (no
`submitted` state is ever reached). `held -> held` covers moving an
already-held document to a different hold reason.

**The two post-`valid` edges in the diagram above are detected by a periodic
refresh, not by the poller.** `PollSubmission` stops re-checking a submission
as soon as every document in it is final, so `valid -> rejected` (the buyer
rejects) and a cancellation performed directly in the MyInvois portal would
never be seen by the polling path on their own. Instead, `App\Jobs\RefreshDocumentStatus`
(dispatched by the `einvoice:lhdn-dispatch` sweep, not by the submission
pipeline) calls `getDocument` for one already-`valid` document and applies
`rejected`/`cancelled` via `DocumentStateMachine::applyLhdnVerdict()` if
LHDN reports either. A document is only refreshed while it's within
`lhdn.status_refresh.max_age_days` of validation (default 7) and it hasn't
been refreshed in the last `lhdn.status_refresh.interval_hours` (default 6);
the sweep caps how many refreshes it dispatches per issuer per run
(`LhdnDispatch::REFRESH_SWEEP_LIMIT_PER_ISSUER`, 50) so one issuer's backlog
can't starve the rest. `lhdn_refreshed_at` is stamped on every check
(including a `getDocument` 404, which is treated as a stable "LHDN doesn't
know this document" answer for that cycle) so the sweep always picks the
least-recently-refreshed documents next.

## 2. Components

| Piece | File | Role |
|---|---|---|
| `LhdnClient` | `app/Lhdn/LhdnClient.php` | Interface: `token`, `submitDocuments`, `getSubmission`, `getDocument`, `cancelDocument`, `validateTin`. |
| `HttpLhdnClient` | `app/Lhdn/Http/HttpLhdnClient.php` | Real MyInvois HTTP implementation. Wraps every call with the rate limiter, circuit breaker and `submission_attempts` recording; classifies non-2xx responses into `LhdnErrorKind`. |
| `FakeLhdnClient` | `app/Lhdn/Fake/FakeLhdnClient.php` | Deterministic in-memory client used by every Feature/Unit test (`config('lhdn.driver') === 'fake'`, the default in `phpunit.xml`). |
| `LhdnClientFactory` | `app/Lhdn/LhdnClientFactory.php` | `for(Issuer)` resolves a client for that issuer's `lhdn_mode`/environment (or the fake, if configured); `forEnvironment(Environment)` gets the intermediary client without a specific issuer's own credentials (used for TIN validation before an issuer exists in some flows). |
| `CredentialsResolver` | `app/Lhdn/CredentialsResolver.php` | Resolves intermediary vs. own-credentials `LhdnCredentials` per issuer. |
| `TokenProvider` | `app/Lhdn/Http/TokenProvider.php` | Redis-cached OAuth2 client-credentials token, keyed per environment/credentials, with single-flight to avoid stampedes. |
| `CircuitBreaker` | `app/Lhdn/CircuitBreaker.php` | Opens **per environment** (`lhdn:breaker:open:{env}`) after `circuit_breaker.failure_threshold` consecutive failures; `guard()` throws a `breaker`-kind `LhdnException` while open. |
| `LhdnRateLimiter` | `app/Lhdn/LhdnRateLimiter.php` | Token-bucket-style limit **per issuer per operation** (`lhdn:{operation}:{issuer_id}`), budgets from `lhdn.rate_limits.*`. |
| `AttemptRecorder` | `app/Lhdn/Http/AttemptRecorder.php` | Writes every LHDN request/response to `submission_attempts`. |
| `UblDocumentBuilder` | `app/Lhdn/Ubl/*` | Builds the UBL 2.1 JSON payload from a `Document`. |
| `DocumentSigner` | `app/Lhdn/Signing/*` | Signs the UBL JSON with the issuer's certificate. |
| `PrepareDocument` | `app/Jobs/PrepareDocument.php` | `queued` -> build UBL -> sign -> store `ubl_json`/`signed_payload_hash`/`lhdn_internal_id` -> dispatch `SubmitDocuments`. Holds the document instead if the issuer isn't active, has no valid certificate, or the LHDN payload size limit is exceeded (`Invalid` for the size case). |
| `SubmitDocuments` | `app/Jobs/SubmitDocuments.php` | Batches one issuer's prepared, due (`next_submission_at`) documents (oldest first, up to `submission.max_documents` / `submission.max_bytes`) into one `submitDocuments` call; settles accepted documents to `submitted` and dispatches `PollSubmission`; settles rejected ones to `invalid`; on a client exception, applies retry/backoff or holds per §4 below. |
| `PollSubmission` | `app/Jobs/PollSubmission.php` | Polls `getSubmission` for one `submissionUid`; settles each document to `valid`/`invalid`; reschedules itself along `poll.backoff_seconds` until the submission is final. A failed *read* never settles a document (see §4); at the end of the curve it falls back to a per-document `getDocument` check. It can also settle a previously-`valid` document reported `rejected`/`cancelled`, but only if a poll happens to run at that moment; the reliable path for those two edges is `RefreshDocumentStatus` (row below). |
| `ReleaseHeldDocuments` | `app/Jobs/ReleaseHeldDocuments.php` | Re-queues documents held for a releasable reason once the issuer activates. |
| `RefreshDocumentStatus` | `app/Jobs/RefreshDocumentStatus.php` | Re-reads one already-`valid` document via `getDocument` and applies `rejected`/`cancelled` if LHDN's answer changed; stamps `lhdn_refreshed_at` either way. Dispatched only by the `einvoice:lhdn-dispatch` sweep — see §1. |
| `einvoice:lhdn-dispatch` | `app/Console/Commands/LhdnDispatch.php` | Safety-net sweep (see §3), also the sole driver of `RefreshDocumentStatus`. |

## 3. Configuration

All keys live in `config/lhdn.php`, env-overridable:

| Key | Env var | Meaning |
|---|---|---|
| `driver` | `LHDN_DRIVER` | `http` (real MyInvois) or `fake` (tests). |
| `environments.{sandbox,production}.api_base` | `LHDN_SANDBOX_API_BASE`, `LHDN_PRODUCTION_API_BASE` | MyInvois API base URL. |
| `environments.{sandbox,production}.identity_base` | `LHDN_SANDBOX_IDENTITY_BASE`, `LHDN_PRODUCTION_IDENTITY_BASE` | OAuth2 token endpoint base URL. |
| `environments.{sandbox,production}.portal_base` | `LHDN_SANDBOX_PORTAL_BASE`, `LHDN_PRODUCTION_PORTAL_BASE` | Public MyInvois portal, used to build `validation_url`. |
| `intermediary.{sandbox,production}.client_id/client_secret` | `LHDN_SANDBOX_CLIENT_ID`/`_SECRET`, `LHDN_PRODUCTION_CLIENT_ID`/`_SECRET` | Billplz's own intermediary credentials (used with `onbehalfof: <issuer TIN>` for issuers in `lhdn_mode: intermediary`). |
| `timeout` | `LHDN_TIMEOUT` | HTTP client timeout (seconds). |
| `token_ttl_margin_seconds` | — | Subtracted from `expires_in` before caching a token, so it's never used right up to expiry. |
| `tin_cache_hours` | — | How long a validated TIN result is cached. |
| `rate_limits.{token,submit,get_submission,get_document,cancel,validate_tin}` | — | Per-issuer, per-operation, per-minute budgets. |
| `circuit_breaker.failure_threshold` / `cooldown_seconds` | — | Consecutive-failure threshold and open-circuit cooldown, per environment. |
| `submission.max_documents` / `max_bytes` / `max_document_bytes` | — | Batch caps (documents per call, total wire bytes per call, wire bytes per document). |
| `submission.max_attempts` / `retry_backoff_seconds` | — | Retry ceiling and backoff curve (seconds) for transient submission failures before a document is held with `lhdn_unavailable`. |
| `poll.backoff_seconds` | — | Backoff curve `PollSubmission` walks between polls of one submission. |
| `status_refresh.max_age_days` | `LHDN_STATUS_REFRESH_MAX_AGE_DAYS` | A `valid` document stops being refreshed once it's older than this many days past validation (default 7 — matches the cancellation window). |
| `status_refresh.interval_hours` | `LHDN_STATUS_REFRESH_INTERVAL_HOURS` | Minimum gap between two `RefreshDocumentStatus` checks of the same document (default 6). |
| `duplicate_rejection_codes` | — | LHDN rejection codes treated as "this codeNumber was already submitted" for the duplicate-recovery path in §4 (default `['DUPLICATE_SUBMISSION']`); a rejection message matching `/duplicat/i` is also recognised regardless of code. |

## 4. Error handling & `submission_attempts`

Every LHDN client call raises `App\Lhdn\LhdnException` with a `LhdnErrorKind`:

- `transient` (429, 5xx, network failure) — retried with the configured
  backoff; once `submission.max_attempts` is exhausted the document(s) move to
  `held` with reason `lhdn_unavailable`.
- `auth` (401/403) — the issuer's credentials are wrong; documents move to
  `held` with reason `lhdn_credentials_invalid` and the cached token for that
  credential set is dropped so the next attempt re-authenticates.
- `terminal` (any other non-2xx, e.g. 400 validation) — what this means depends
  on which call failed:
  - `submitDocuments` with **400 or 422**: LHDN rejected the payload we sent, so
    every document in the batch moves to `invalid` with `lhdn_errors` populated
    from the response. Any *other* terminal status from `submitDocuments` (404,
    405, 408, 409, …) is about the request plumbing rather than the invoices, so
    it takes the transient retry/backoff path instead.
    - **Exception: a duplicate-`codeNumber` rejection recovers instead of
      going `invalid`.** If the rejection's code is in
      `lhdn.duplicate_rejection_codes` (or its message matches `/duplicat/i`),
      `SubmitDocuments::recoverDuplicate()` looks back through the issuer's
      last 20 `submit` `submission_attempts` for the uuid LHDN already
      assigned that `codeNumber` — the situation this covers is a worker
      dying between LHDN accepting a document and that acceptance being
      saved, so a resubmit gets told the codeNumber already exists. When a
      matching prior attempt with a uuid is found, the document adopts it,
      transitions to `submitted` (event `duplicate_recovered`), and
      `PollSubmission` resumes against the recovered `submission_uid`. If no
      matching attempt (or no uuid on it) is found, the rejection falls
      through to the normal `invalid` handling.
  - `getSubmission`: never a verdict on the documents. A failed poll — terminal,
    transient or `breaker` alike — only reschedules the poll along
    `poll.backoff_seconds`. When that curve is exhausted, `PollSubmission` asks
    LHDN about each still-`submitted` document individually via `getDocument`
    and settles it from that answer (`Valid` -> `valid` + `longId`, `Invalid` ->
    `invalid` + validation errors). If the per-document read also fails the
    document simply stays `submitted` and the `einvoice:lhdn-dispatch` sweep
    re-polls it later. Nothing ever fabricates an `LHDN_4xx` error onto a
    document from a submission-level read failure.
- `breaker` — the circuit breaker for that environment is open; thrown by
  `CircuitBreaker::guard()` before any request is sent.

Only failures that actually reached LHDN move the breaker, and only
platform-level ones: connection errors and 5xx responses on a call that was actually sent (a connection error raised while fetching the token is recorded and counted once, by the token fetch itself). An HTTP 429 *from*
MyInvois is a per-taxpayer rate limit, not an outage, so it never opens the
breaker for the whole environment; neither does our own `LhdnRateLimiter`
rejection or an already-open breaker, which are refused before any request is
sent and therefore write no `submission_attempts` row either (this holds for
the token path as well as for API calls).

A document that can never be *prepared* (a builder/signing bug rather than an
LHDN response) is counted in `submission_attempts_count` with a
`last_submission_error` of kind `prepare`, and the job rethrows so the queue
retries it; once `submission.max_attempts` is reached it is held with
`lhdn_unavailable` and reason `prepare_failed`. It is never marked `invalid` —
the merchant's data was fine.

Every attempt — successful or not, including the token fetch — is recorded to
`submission_attempts` (`App\Models\SubmissionAttempt`) by `AttemptRecorder`:
tenant/issuer/document, `operation` (`token`, `submit`, `get_submission`,
`get_document`, `cancel`, `validate_tin`), `environment`, `http_status`,
`request`/`response` JSON summaries, `error_kind`, `error_message`,
`duration_ms`. This is the audit trail for "what did we send LHDN and what did
it say back" — query it by `document_id` or `submission_uid` to debug a stuck
document. It is retained indefinitely alongside documents (7-year LHDN
retention requirement, spec §7.5); nothing prunes it automatically.

A document's own `submission_attempts_count`, `last_submission_error` and
`next_submission_at` columns track only the submission-batch retry loop (not
polling or other operations) and drive `SubmitDocuments`'s "is this document
due yet" query.

## 5. Running the pipeline

Two long-running processes, in addition to `php artisan serve`:

```
php artisan queue:work        # runs PrepareDocument, SubmitDocuments, PollSubmission, ReleaseHeldDocuments
php artisan schedule:work      # drives the einvoice:lhdn-dispatch safety net (see routes/console.php)
```

`einvoice:lhdn-dispatch` (scheduled every minute, `withoutOverlapping`) is the
safety net behind the event-driven chain described in §1–§2: it walks every
tenant and environment and re-dispatches whatever the fast path dropped —
`queued` documents with no `ubl_json` older than a minute (a prepare that
never landed), issuers with due (`next_submission_at`) queued+prepared
documents (a submit that never fired), and submissions still `submitted`
after two minutes (a poll that never completed or exhausted its backoff). It
is not how a healthy system usually makes progress; it exists so a dead
worker or an LHDN outage self-heals within a minute instead of silently
stalling a document forever.

## 6. Onboarding an issuer

1. `POST /v1/issuers` — create the issuer (`draft` status). For `lhdn_mode:
   own_credentials`, also `PUT /v1/issuers/{id}/credentials`.
2. `POST /v1/issuers/{id}/verify-tin` — calls `validateTin` against LHDN for
   the issuer's TIN/id_type/id_number. On success sets `tin_verified_at` and
   advances `draft -> tin_verified`. Returns 422 (`tin_invalid`) if LHDN
   doesn't recognise the combination.
3. `POST /v1/issuers/{id}/authorize` — fetches a real token for the issuer
   (via the intermediary or the issuer's own credentials, per `lhdn_mode`).
   On success sets `authorized_at` and advances to `authorized`. Requires
   `tin_verified_at` to already be set (409 `tin_not_verified` otherwise). For
   intermediary mode, a 401/403 here usually means the merchant hasn't
   granted Billplz intermediary access to their TIN in the MyInvois portal
   yet — the error message says so.
4. `PUT /v1/issuers/{id}/certificate` — upload the signing certificate (PEM or
   PKCS#12). Once the issuer is `authorized` **and** has a valid (unexpired)
   certificate, `IssuerActivator` moves it to `active` and fires
   `IssuerActivated`, which releases any documents held for
   `issuer_not_active`/`certificate_expired`.
5. Documents for an `active` issuer flow straight through `queued ->
   submitted -> valid` via the pipeline in §1–§2. Documents created before the
   issuer is active are held (`issuer_not_active`) and released automatically
   on activation.

A daily job, `einvoice:monitor-certificates` (scheduled 02:00
Asia/Kuala_Lumpur), now notices a certificate expiring on its own (spec
§7.4). It walks every tenant/environment/issuer with a certificate: an
`active` issuer whose certificate has lapsed is suspended — `certificate.expired`
fires and `App\Listeners\HoldDocumentsOnSuspension` (on `IssuerStatusChanged`)
moves that issuer's `queued` documents to `held` with reason
`certificate_expired`, leaving already-`submitted` documents in flight, since
they cannot be recalled; one
still valid but crossing a 30/7/1-day threshold gets a single
`certificate.expiring` webhook per threshold, deduped via
`issuer_secrets.expiry_notified_at_days` (reset to `null` on every new
certificate upload).

Independently of that sweep, `IssuerActivator::apply()` still also
re-evaluates an issuer's status lazily, on `authorize` or a certificate
upload: if it runs and finds the issuer `active` with an expired certificate,
it moves the issuer to `suspended` at that point too — so the status is
corrected either by the next `authorize`/certificate-upload call or, at the
latest, by the next `monitor-certificates` run, whichever comes first.
Uploading a new (valid) certificate always re-activates and releases held
documents, regardless of which path caught the expiry first.

## 7. Sandbox tests

`tests/Integration/LhdnSandboxTest.php` exercises the real MyInvois sandbox
over the network. It is opt-in and skipped by default (`markTestSkipped`) so
`composer check` never touches the network or requires real credentials.

To run it:

```
LHDN_SANDBOX_TESTS=1 \
LHDN_SANDBOX_CLIENT_ID=... LHDN_SANDBOX_CLIENT_SECRET=... \
LHDN_SANDBOX_TEST_TIN=... LHDN_SANDBOX_TEST_ID_TYPE=BRN LHDN_SANDBOX_TEST_ID_VALUE=... \
php artisan test tests/Integration
```

`LHDN_SANDBOX_TEST_ID_TYPE` defaults to `BRN` if unset. The intermediary
`LHDN_SANDBOX_CLIENT_ID`/`LHDN_SANDBOX_CLIENT_SECRET` must already have
sandbox access granted for the test TIN (see §6 step 3). The suite covers:

1. fetching a real token as intermediary for a sandbox issuer;
2. `validateTin` for the configured test TIN, expecting `true`;
3. `getDocument` for a made-up UUID, expecting a terminal `LhdnException`
   (real 404 handling).

It deliberately does **not** submit a document: a real sandbox submission
creates a permanent MyInvois record. A manual sandbox smoke-submit command is
out of scope for this suite.

Never commit real sandbox credentials or TINs — set them as local environment
variables or in a git-ignored `.env.sandbox`, never in `.env.example`,
fixtures, or tests.

## 8. Decisions

- **`lhdn_internal_id`** is set to the document's own ULID at creation
  (`documents.id`) and sent to LHDN as `codeNumber` (submission) /
  `Invoice.ID` (UBL), so the engine's internal identifier and the
  LHDN-facing one never diverge — no separate mapping table, no risk of the
  two drifting apart under retries.
- **Circuit breaker scope is per environment**, not per issuer: MyInvois
  outages are platform-wide, so one issuer's failures should stop batching
  for every issuer in that environment rather than pretending the API is
  still healthy for everyone else.
- **Rate limiting scope is per issuer, per operation**: MyInvois publishes
  limits per taxpayer (issuer) per API, so the limiter key
  (`lhdn:{operation}:{issuer_id}`) mirrors that rather than sharing a single
  budget across issuers or operations.
- **Retry/backoff curves live in `config/lhdn.php`**
  (`submission.retry_backoff_seconds`, `poll.backoff_seconds`) rather than
  being hardcoded in the jobs, so they can be tuned per environment/deployment
  without a code change.

- **Status-refresh, certificate monitor, webhooks, consolidation and PDF are
  all built (Plan 4).** Status-refresh (§1, §2) and the certificate monitor
  (§6) are covered above since they're part of this pipeline; webhook
  delivery, PDF generation, and monthly B2C consolidation are documented in
  the README and in spec §5.6/§7.2/§7.3 (this doc stays scoped to the
  submission/status pipeline itself).
