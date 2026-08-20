# Onboarding Dashboard — Design (Plan 5)

**Status:** approved design, 2026-08-20.
**Parent spec:** `2026-08-19-einvoice-engine-design.md` (§13.2 names this project). The engine spec remains the source of truth for the API, tenancy, and domain rules; this document only adds the dashboard subsystem. Where this document is silent, the engine spec and `CLAUDE.md` apply unchanged.

## 1. Purpose & scope

The first human surface on the engine: a merchant/tenant dashboard for self-service onboarding and operations, served by the same Laravel application.

In scope (Plan 5):
1. Issuer onboarding wizard (profile → TIN verify → LHDN mode → certificate → sandbox test → go live).
2. API key management.
3. Webhook endpoint management + delivery log.
4. Document browser (list, detail, PDF, cancel/resubmit).
5. Marketplace vendor onboarding (invite vendors as scoped users; progress board).

Out of scope (recorded, not designed here): Billplz account SSO (the magic-link step is designed to be replaced by it later), billing, analytics, admin/back-office tooling, public signup.

## 2. Locked decisions

| Decision | Choice | Source |
|---|---|---|
| Wiring | **Approach A** — session-authenticated web controllers calling the same domain actions/DTOs as `/v1`; no internal HTTP | user, 2026-08-20 |
| Sign-in | **Magic-link / passwordless**; no password column, ever | user, 2026-08-20 |
| Mail | **Mailtrap** (SMTP) for magic links and invites; `array` mailer in tests | user, 2026-08-20 |
| Provisioning | **Invite-only, multi-user**; tenants still provisioned by Billplz (artisan/service API) | user, 2026-08-20 |
| Roles | `owner`, `member` (tenant-wide) and `vendor` (issuer-scoped) | user, 2026-08-20 |
| Vendor access | Vendors get **full dashboard accounts** scoped to their own issuer | user, 2026-08-20 |
| Front end | **Inertia v2 + React + TypeScript** via Vite, served by the engine app | user, 2026-08-19 |

## 3. Architecture

```
routes/web.php
  └─ middleware: session auth → EnsureDashboardTenantContext → Inertia shared props
       └─ app/Http/Controllers/Web/* (thin; authorize via policy, call action, return Inertia page)
            └─ the SAME actions & spatie Data DTOs the /v1 API uses (direct method calls)
                 └─ TenantContext → MySQL / LHDN gateway
```

- Web controllers live in `app/Http/Controllers/Web`; pages in `resources/js/Pages`. Controllers contain no domain logic — they authorize, call an existing action, and render (or redirect with a flash). If a needed operation has no action yet, the action is added to `app/Actions` (HTTP-free) and the API may adopt it later; logic is never written in a web controller.
- Spatie Data DTOs are passed directly as Inertia props (their JSON shape is already `snake_case`; same serialization the API produces). **The `app/Data/Resources` DTO is the only shape a page may receive for a domain object** — no ad-hoc arrays of model attributes.
- `TenantContext` binds from the authenticated user's tenant; environment binds from the session (see §4.4). Request input never carries `tenant_id` — unchanged.
- Validation errors flow through Inertia's standard error bag (session-flashed), not `problem+json`; `problem+json` remains API-only. Inertia error pages for 403/404/419/500.

## 4. Auth & user model

### 4.1 `users`

ULID PK, `BelongsToTenant`. Columns: `tenant_id`, `name`, `email` (**globally unique** — the login identifier; one user belongs to exactly one tenant), `role` enum (`owner|member|vendor`), `issuer_id` nullable FK (**set if and only if `role = vendor`**; DB CHECK constraint), `invited_at`, `last_login_at`, timestamps. **No password column.** Unique index on `email` (global, not per-tenant); index `(tenant_id, role)`.

A user is *invited* until `last_login_at` is first set; the invited/active distinction is derived, not a column.

### 4.2 Magic links

- `login_tokens` table: ULID PK, `user_id`, `token_hash` (SHA-256 — plaintext never stored), `expires_at` (15 minutes), `consumed_at` nullable, `created_at`. Index `(user_id)`, unique `(token_hash)`.
- `POST /login/link` accepts an email, always answers with the same "if that address exists, we sent a link" page (no user enumeration), throttled per email and per IP (5/min). If the user exists, a single-use signed URL (`GET /login/{token}`) is mailed via Mailtrap.
- Consuming a link: valid + unexpired + unconsumed → mark consumed, set `last_login_at`, regenerate the session, log in via the standard `web` session guard, audit `user.logged_in`. Invalid/expired/consumed → one generic error page offering to send a new link. All outstanding tokens for a user are invalidated on successful login.
- Logout: `POST /logout` invalidates the session. Session lifetime and idle timeout use Laravel defaults; remember-me is not offered (magic links are cheap).

### 4.3 Invites

- First user: `php artisan einvoice:invite-user {tenant} {email} --role=owner --name=` creates the user row and mails a magic link. Also usable for support/recovery.
- Owners invite members and other owners from the Team page. Owners **and** members invite vendors from the Vendors page; a vendor invite either creates a new issuer (name only — a shell the vendor completes) or targets an existing not-yet-onboarded issuer, and pins the user to it via `issuer_id`.
- An invite **is** a user row plus a magic link; re-inviting re-sends a fresh link. Deleting a never-logged-in user cancels the invite. Invite emails are audited (`user.invited`, without any token material).
- Email collision (already a user anywhere) → validation error; a user is never moved between tenants or issuers by invite.

### 4.4 Environment switcher

The header carries a sandbox/production switcher; the choice is stored in the session (default `sandbox`) and `EnsureDashboardTenantContext` binds it exactly as `X-Environment` does for service tokens. Every list, action, key, webhook, and document view is environment-scoped by the existing global scopes. Pages visibly label the production environment. The switch is `POST /environment` (owner/member; vendors can switch too — their issuer scoping is orthogonal).

## 5. Authorization

Laravel policies, registered explicitly. Role matrix (route-level detail in §8):

| Capability | owner | member | vendor |
|---|---|---|---|
| Issuer wizard (own issuer) | ✓ (any issuer) | ✓ (any issuer) | ✓ (only `issuer_id`) |
| Go live (production activation) | ✓ | — | — |
| Documents (list/detail/PDF/cancel/resubmit) | ✓ all | ✓ all | ✓ own issuer only |
| Webhooks CRUD + deliveries | ✓ | ✓ | — |
| API keys | ✓ | — | — |
| Team (invite/remove users) | ✓ | — | — |
| Vendor invites + progress board | ✓ | ✓ | — |

- Vendor scoping is enforced in policies **and** in queries (vendor list pages filter by `issuer_id`); a vendor hitting another issuer's resource gets **404** (existence not confirmed), matching the engine's cross-tenant rule. Within-tenant role denials (e.g. member opening API keys) are **403** pages.
- Cross-tenant remains 404 everywhere; every ID-carrying web route gets a sweep row (§10).

## 6. Feature areas

### 6.1 Issuer onboarding wizard

Six steps. **No wizard-state table** — each step's done/pending state derives from the issuer row, its secrets, and its status, so the wizard is resumable and the same derivation powers the vendor progress board (§6.5). A single `IssuerOnboardingState` (read-only domain class, `app/Domain/Onboarding`) computes `{step, done, blocked_reason}` per step and is unit-tested table-driven.

1. **Profile** — business name, TIN, registration (BRN/NRIC), MSIC, SST no., address, contact. Writes via existing issuer update action.
2. **TIN verify** — calls the existing `VerifyIssuerTin`; shows the verdict inline; stores nothing new.
3. **LHDN mode** — choose per environment: intermediary (records consent text + timestamp in the existing fields) or own credentials (client ID/secret form → existing secret storage; secret never redisplayed).
4. **Certificate** — upload PKCS#12 + passphrase via the existing certificate endpoint's action; page shows only `CertificateMetaData` (subject, serial, not-after). Never the material.
5. **Sandbox test** — visible only in the sandbox environment. One click creates and submits a real sample invoice (fixed template, `source_system=dashboard-test`, unique `source_ref` per attempt so repeats are allowed) for this issuer via `CreateDocument(submit: true)`; the page shows live status via Inertia partial-reload polling until `valid`/`invalid`/`held` and surfaces LHDN errors from the document's events/attempts. Passing = a `valid` sandbox document exists for the issuer.
6. **Go live** — owner-only, and only enabled when steps 1–5 are done for sandbox and steps 3–4 are done for **production** (credentials/consent and certificate are per environment — the wizard prompts for the production repeats inline here). Runs the existing authorize/activation flow in production. The engine's `IssuerActivator` remains the only authority on `active`.

### 6.2 API keys (owner-only)

List (prefix, environment, abilities, created/last-used, revoked state), create (abilities picker + environment; **plaintext shown exactly once** in a copy-to-clipboard modal, never retrievable), revoke (confirm dialog). Uses the existing `ApiKey` issuance/revocation code paths and audit events.

### 6.3 Webhooks

CRUD mirroring the API (URL validated by the same `PublicHttpsUrl` rule, events picker from `WebhookEvent`, secret shown once on create), enable/disable, test button, deliveries log per endpoint (status, attempt, http_status, response snippet, next retry) with redeliver. All via the existing webhook actions.

### 6.4 Document browser

- List: cursor-paginated, filters status / type / issuer / date-range / `source_ref` search; environment-scoped; vendors auto-filtered to their issuer. Columns: ref, type, status badge, issuer, buyer name, total, issue date, LHDN uuid.
- Detail: header (status, uuid, long id, validation link), lines + totals, events timeline (from `document_events`), latest LHDN errors (parsed from the document's rejection/invalid event payloads and submission attempts — response bodies are already redacted at storage time), consolidation links (parent/children) when present.
- Actions per state: **PDF download** (valid only — existing generator), **cancel** (within the 72h window, reason required), **resubmit** (where the state machine allows). All through existing actions; buttons render only when the transition is legal.

### 6.5 Vendor onboarding (marketplace)

- **Invite** (owner/member): email + vendor business name → creates the issuer shell + vendor user + magic link (§4.3).
- **Vendor experience:** a vendor lands on *their* wizard (§6.1) — same pages, scoped by policy; steps 1–5 identical; go-live remains owner-only (tenant staff flips the switch after reviewing).
- **Progress board** (owner/member): one row per vendor issuer — vendor name, invited user + invited/active, current wizard step from `IssuerOnboardingState`, issuer status badge, last activity; re-invite button. No extra tables: the board is a query over issuers + users + derived state.

## 7. Frontend stack & conventions

- Inertia v2, React 18, TypeScript (strict), Vite. Tailwind CSS. A small in-repo component set under `resources/js/Components` (layout shell with sidebar nav + tenant name + env switcher + user menu, form fields, table, status badge, confirm dialog, copy-once secret modal). No heavyweight UI-kit dependency.
- Status badges use one shared mapping from `DocumentStatus`/issuer status/delivery status to colors — single source in `resources/js/lib/status.ts`.
- Shared Inertia props: `auth.user` (id, name, email, role, issuer_id), `tenant` (name), `environment`, `flash`. Nothing else is global.
- `npm run build` at deploy; build output is not committed. `npm run lint` (eslint + prettier) exists but `composer check` remains the only merge gate.

## 8. Web routes

All under session auth except the login group. `{id}`s are ULIDs; route-model binding uses the tenant-scoped resolvers.

| Method/Path | Controller action | Policy |
|---|---|---|
| GET `/login`, POST `/login/link`, GET `/login/{token}` | guest only | — |
| POST `/logout`, POST `/environment` | any user | — |
| GET `/dashboard` | overview (counts by status, recent documents, cert expiry warnings) | any |
| GET `/issuers`, POST `/issuers` | issuer list + create shell (name only; wizard fills the rest). Vendors: redirected to their own wizard | owner/member |
| GET `/issuers/{id}/onboarding` | wizard shell (step from `IssuerOnboardingState`) | per §5 |
| PATCH `/issuers/{id}/profile` · POST `/issuers/{id}/verify-tin` · POST `/issuers/{id}/mode` · POST `/issuers/{id}/certificate` · POST `/issuers/{id}/sandbox-test` · POST `/issuers/{id}/go-live` | wizard steps (§6.1) | per §5; go-live owner |
| GET `/documents`, GET `/documents/{id}`, GET `/documents/{id}/pdf`, POST `/documents/{id}/cancel`, POST `/documents/{id}/resubmit` | §6.4 | per §5 |
| GET `/webhooks`, POST `/webhooks`, PATCH `/webhooks/{id}`, DELETE `/webhooks/{id}`, POST `/webhooks/{id}/test`, GET `/webhooks/{id}/deliveries`, POST `/webhook-deliveries/{id}/redeliver` | §6.3 | owner/member |
| GET `/api-keys`, POST `/api-keys`, DELETE `/api-keys/{id}` | §6.2 | owner |
| GET `/team`, POST `/team/invites`, POST `/team/{user}/reinvite`, DELETE `/team/{user}` | §4.3 | owner |
| GET `/vendors`, POST `/vendors/invites`, POST `/vendors/{user}/reinvite` | §6.5 | owner/member |

## 9. Security rules (additive to CLAUDE.md)

- Magic-link tokens: only hashes at rest; links single-use, 15-min expiry, throttled, enumeration-safe; consuming regenerates the session id.
- **No secret ever reaches Inertia props**: LHDN client secrets, certificate material/passphrases, API-key plaintext (outside the one-time create response), webhook secrets (outside the one-time create response), magic-link tokens. Tests assert prop shapes explicitly for every page touching a secret-bearing model.
- Copy-once values are delivered in the redirect's flash payload once and never persisted client-side.
- CSRF on every POST/PATCH/DELETE (Laravel default); uploads (certificates) size-capped and validated as PKCS#12 by the existing action.
- Vendors: `issuer_id` is immutable from the dashboard; only re-invite/remove exists. Role changes are not offered in v1 (delete + re-invite instead).
- Audit events for: login, invite, re-invite, user removal, environment switch is *not* audited (noise), go-live and all secret-touching operations already audited by their actions.

## 10. Testing rules (additive)

- Pest + Inertia testing helpers. Per web route: auth redirect (guest → `/login`), role matrix (owner/member/vendor as applicable), vendor issuer-scoping (other issuer → 404), cross-tenant 404 rows in `TenantIsolationSweepTest` (web section) for every ID-carrying route, validation error path.
- Magic links: happy path, expired, consumed, tampered, throttle, enumeration-identical responses, session regeneration.
- `IssuerOnboardingState`: table-driven over issuer fixtures (each step done/pending/blocked).
- Sandbox-test step: uses `FakeLhdnClient` like the pipeline tests; asserts verdict rendering for valid/invalid/held.
- Secret-leak tests per §9. Mail assertions with the `array` mailer.
- No browser/E2E tests in v1; component logic stays thin enough that Pest feature tests cover behaviour. (Recorded as a deliberate gap; revisit if the React layer grows logic.)

## 11. Decisions log

| Date | Decision | Why |
|---|---|---|
| 2026-08-19 | Inertia + React served by the engine app | user |
| 2026-08-20 | Approach A (web controllers over shared actions) over SPA-on-/v1 or separate app | user-level roles fit policies, not API-key abilities; zero new credential subsystem |
| 2026-08-20 | Magic-link auth, Mailtrap; no passwords | user |
| 2026-08-20 | Invite-only multi-user; roles owner/member/vendor; vendors = full accounts scoped by `issuer_id` | user |
| 2026-08-20 | Email globally unique; one user = one tenant | keeps login unambiguous without tenant pickers |
| 2026-08-20 | No wizard-state table; derive from issuer | resumable for free; one source of truth; powers the progress board |
| 2026-08-20 | Go-live owner-only even for vendor issuers | the tenant is legally the intermediary/operator; staff review before production |
| 2026-08-20 | Within-tenant role denial = 403; cross-tenant/cross-issuer = 404 | matches engine rule while keeping honest UX inside a tenant |
