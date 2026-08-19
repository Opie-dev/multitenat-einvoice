# CLAUDE.md — Billplz E-Invoice Engine

Guardrails for any Claude session working in this repo. These override defaults; the spec overrides these when they conflict.

## What this is
Multi-tenant LHDN MyInvois e-invoicing engine (Laravel 12, PHP 8.3+, MySQL 8, Redis) used by Billplz products (Catalog, Recurring, Affiliates, core) and merchants through a REST API (`/v1`). Later: Inertia + React onboarding dashboard.

- **Spec (binding):** `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md`
- **Roadmap:** `docs/superpowers/plans/2026-08-19-einvoice-engine-roadmap.md` (Plans 1–5, executed in order)
- **Plans:** `docs/superpowers/plans/` — write the next plan only after the previous one is merged.

## Workflow (non-negotiable)
1. New feature/subsystem → brainstorm → spec (or spec amendment) → plan (`writing-plans`) → execute (`subagent-driven-development` or `executing-plans`) → review → merge. No code before an approved plan.
2. Work in a git worktree per plan (`.worktrees/<plan>`, branch named after the plan). Never implement directly on `master`.
3. TDD: failing test → minimal code → green → commit. Every task ends with `composer check` (Pint + PHPStan level 8 + Pest) passing with pristine output.
4. Conventional commits (`feat(scope): …`, `fix: …`, `test: …`, `docs: …`, `chore: …`). Commit per task; never commit `.env`, `vendor/`, `node_modules/`, certificates, or keys.
5. When a plan and the spec disagree, the spec wins; record the ruling in the plan's ledger (`.superpowers/sdd/<plan>/progress.md`) and, if it changes the design, amend the spec in the same PR.
6. Do not widen scope. YAGNI. If something is missing from the plan, add a task to the plan (or a follow-up in the roadmap) — don't sneak it in.

## Architecture rules
- **Tenancy:** every tenant-owned model uses `App\Tenancy\BelongsToTenant`; every tenant-owned table has `tenant_id` in its indexes and unique constraints. Never query a tenant-owned model with `withoutGlobalScopes()` outside credential resolution / system jobs, and never accept `tenant_id` from request input. Cross-tenant access returns **404, never 403**.
- **Tenant ≠ issuer.** One tenant may own many issuers (marketplace vendors). Documents always name an `issuer_id`; the engine never decides who the legal seller is — the caller does.
- **Environments:** `sandbox` | `production` are per issuer and per API key (`ek_test_` / `ek_live_`). A credential must never touch the other environment. Service tokens choose via `X-Environment`.
- **Auth:** service tokens (`sk_<service>_…`, `X-Tenant-Id`) for internal products; API keys for merchants. Abilities are enforced by `ability:` middleware. Everything hashed (SHA-256); plaintext shown once.
- **API DTOs:** request validation and response serialisation use `spatie/laravel-data` classes in `app/Data/Requests` and `app/Data/Resources`. **No `FormRequest`, no `JsonResource`.** DTO properties are `snake_case` = JSON keys. Single resources `->wrap('data')`; lists via `XData::collect($paginator, CursorPaginatedDataCollection::class)`.
- **Errors:** every non-2xx is `application/problem+json` (`ProblemException` / `ProblemResponse`). Never leak stack traces or secrets in `detail`.
- **Domain code** (`app/Domain`, `app/Services`) has no HTTP dependencies. Controllers stay thin: DTO in → action/service → DTO out.
- **LHDN gateway** (Plan 3+): all MyInvois calls go through `LhdnClient` implementations; never call LHDN from controllers; respect per-issuer token cache and rate budgets; every request/response is stored in `submission_attempts`.
- **State changes** to documents go through `DocumentStateMachine` only; every transition writes `document_events` and dispatches an event.
- **IDs:** ULIDs (`HasUlids`) on every model. Timestamps stored UTC; business dates in `Asia/Kuala_Lumpur`.

## Security rules
- Secrets (LHDN client secrets, certificates, private keys, passphrases, API-key plaintext) live only in `encrypted` casts / `$hidden`, are never logged, never audited by value, never returned by any endpoint or DTO. Tests must assert this where relevant.
- Never write real merchant TINs, certificates, or LHDN credentials into fixtures, docs, or tests. Test certs are generated locally (`tests/Fixtures/certs`).
- Idempotency and uniqueness constraints are database-enforced, not just application-checked.
- Do not disable Larastan rules or lower the PHPStan level to make CI pass; fix the code or add precise docblocks.

## Testing rules
- Pest. Feature tests use SQLite in-memory (`phpunit.xml`), `FakeLhdnClient` for LHDN, never the network.
- Every new tenant-scoped route gets a row in `tests/Feature/TenantIsolationSweepTest.php`.
- Every new endpoint gets: happy path, validation failure (422 problem+json pointer), ability failure (403), and cross-tenant 404.
- Golden-file tests for UBL output; table-driven tests for state transitions.
- Real LHDN sandbox tests are opt-in only (`LHDN_SANDBOX_TESTS=1`).

## Local commands
```
composer install && cp .env.example .env && php artisan key:generate
docker compose up -d                 # MySQL 8 + Redis (dev only)
php artisan migrate --seed           # seeds LHDN reference codes
composer check                       # pint --test, phpstan (level 8), pest
php artisan einvoice:service-token <service>
php artisan einvoice:refresh-reference-data
```

## When unsure
Read the spec section first, then the plan task. If neither answers, make the smallest reversible choice, write it down (ledger or PR description), and move on. Ask the human only for irreversible, security-sensitive, or scope-changing decisions.
