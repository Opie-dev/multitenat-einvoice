# Billplz E-Invoice Engine

Multi-tenant LHDN MyInvois e-invoicing service used by Billplz products (Catalog, Recurring, Affiliates, core) and merchants via a REST API.

- Design spec: `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md`
- Roadmap: `docs/superpowers/plans/2026-08-19-einvoice-engine-roadmap.md`

## Local setup

    cp .env.example .env && composer install && php artisan key:generate
    docker compose up -d            # MySQL 8 + Redis
    php artisan migrate --seed      # seeds LHDN reference codes
    php artisan einvoice:service-token catalog   # prints a service token once
    php artisan serve

## Quality gates

    composer check   # pint + phpstan (level 8) + pest

`composer check` must be green before every commit.

## Auth model

| Credential | Header | Tenant | Environment |
|---|---|---|---|
| Service token `sk_<service>_…` | `Authorization: Bearer`, `X-Tenant-Id`, `X-Environment` | from header | from header (default production) |
| API key `ek_test_…` / `ek_live_…` | `Authorization: Bearer` | bound to key | bound to key |

All errors are `application/problem+json`. Cross-tenant resources are always `404`.

## LHDN

Documents move `queued -> submitted -> valid` through the LHDN MyInvois
gateway (`app/Lhdn/*`, jobs in `app/Jobs/*`). Two processes drive the pipeline
in addition to `php artisan serve`:

    php artisan queue:work    # PrepareDocument, SubmitDocuments, PollSubmission, ReleaseHeldDocuments
    php artisan schedule:work # runs einvoice:lhdn-dispatch every minute (safety net)

Key env vars (`config/lhdn.php`):

| Var | Meaning |
|---|---|
| `LHDN_DRIVER` | `http` (real MyInvois) or `fake` (tests; default in `phpunit.xml`) |
| `LHDN_SANDBOX_API_BASE` / `LHDN_SANDBOX_IDENTITY_BASE` / `LHDN_SANDBOX_PORTAL_BASE` | sandbox base URLs |
| `LHDN_PRODUCTION_API_BASE` / `LHDN_PRODUCTION_IDENTITY_BASE` / `LHDN_PRODUCTION_PORTAL_BASE` | production base URLs |
| `LHDN_SANDBOX_CLIENT_ID` / `LHDN_SANDBOX_CLIENT_SECRET` | Billplz intermediary credentials, sandbox |
| `LHDN_PRODUCTION_CLIENT_ID` / `LHDN_PRODUCTION_CLIENT_SECRET` | Billplz intermediary credentials, production |
| `LHDN_TIMEOUT` | HTTP client timeout (seconds) |

Full pipeline, config, onboarding, error-handling and sandbox-test details:
`docs/lhdn-gateway.md`.

## Webhooks

`webhook_endpoints` (per tenant, per environment) subscribe to a set of
events; each matching event creates a `webhook_deliveries` row and dispatches
`App\Jobs\DeliverWebhook`, a custom tenant-aware queued job (not
spatie/laravel-webhook-server).

| Method | Path | Notes |
|---|---|---|
| GET/POST | `/v1/webhooks` | list / create (secret returned once, in the create response only) |
| GET/PATCH/DELETE | `/v1/webhooks/{id}` | |
| GET | `/v1/webhooks/{id}/deliveries` | delivery log for one endpoint |
| POST | `/v1/webhooks/{id}/test` | sends a synthetic `webhook.test` payload |
| POST | `/v1/webhook-deliveries/{id}/redeliver` | clones a past delivery and re-sends it |

Events: `document.validated`, `document.held`, `document.queued`,
`document.submitted`, `document.valid`, `document.invalid`,
`document.cancelled`, `document.rejected`, `document.consolidated`,
`document.consolidation_failed`, `issuer.status_changed`,
`certificate.expiring`, `certificate.expired`.

Every delivery is a `POST` with header `X-Einvoice-Event: <event>` and
`X-Einvoice-Signature: <hex hmac-sha256>` computed over the exact request
body. Failed deliveries retry along `[60, 300, 1800, 7200, 21600, 86400]`
seconds (about a day total) before the delivery is marked `exhausted`;
`webhook_deliveries.status`/`attempt`/`next_retry_at` track progress and
`GET /v1/webhooks/{id}/deliveries` lists the history.

Verifying a delivery in PHP:

```php
$payload = file_get_contents('php://input');
$expected = hash_hmac('sha256', $payload, $webhookSecret);

if (! hash_equals($expected, $_SERVER['HTTP_X_EINVOICE_SIGNATURE'] ?? '')) {
    http_response_code(400);
    exit;
}

$event = json_decode($payload, true);
// $event = ['id' => '...', 'event' => 'document.valid', 'created_at' => '...', 'data' => [...]]
```

Use `hash_equals()` (constant-time comparison), verify against the *raw*
request body (not a re-encoded copy of the parsed JSON), and read the secret
only from your own stored copy — it is shown once, at creation, and never
returned by any `GET`.

## PDF

`GET /v1/documents/{id}/pdf` returns the rendered invoice PDF (with the LHDN
validation QR code) once the document is `valid`, `cancelled` or `rejected`.
Generation is lazy — the first request after a status change renders and
caches it; a later request for a stale cache (e.g. after a cancellation)
regenerates it automatically. Requesting it before the document is validated
returns `409 pdf_not_available`.

## Scheduled commands

Run alongside `queue:work` via `php artisan schedule:work` (or your
platform's cron -> `artisan schedule:run` setup):

| Command | Schedule | Purpose |
|---|---|---|
| `einvoice:lhdn-dispatch` | every minute | submission pipeline safety net + `valid`-document status refresh (buyer rejection / portal cancellation) |
| `einvoice:consolidate` | 01:00 Asia/Kuala_Lumpur, daily | folds the previous month's B2C receipts into one consolidated invoice per issuer/currency |
| `einvoice:monitor-certificates` | 02:00 Asia/Kuala_Lumpur, daily | certificate expiry notices (30/7/1 days) and automatic suspension of issuers with a lapsed certificate |
| `einvoice:prune-attempts` | 03:30 Asia/Kuala_Lumpur, daily | deletes `submission_attempts` rows past the retention window (`--days` to override) |

`einvoice:consolidate` and `einvoice:monitor-certificates` can also be run
by hand (e.g. `php artisan einvoice:consolidate --month=2026-07`).

See `CLAUDE.md` for guardrails (tenancy, auth, testing, and security rules).
