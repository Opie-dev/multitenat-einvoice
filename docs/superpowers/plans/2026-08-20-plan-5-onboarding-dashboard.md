# Plan 5 — Onboarding Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the merchant/tenant dashboard (Inertia v2 + React + TypeScript) on the engine app: magic-link auth with invite-only users (owner/member/vendor), issuer onboarding wizard, document browser, webhooks + API keys pages, and marketplace vendor onboarding.

**Architecture:** Approach A — a session-authenticated web surface (`routes/web.php`) whose thin controllers call the **same domain actions and spatie Data DTOs the `/v1` API uses**, with Laravel policies enforcing the role matrix and `TenantContext` bound from the logged-in user + a session environment switcher. No internal HTTP, no personal API tokens, no wizard-state table (state derives from the issuer).

**Tech Stack:** Laravel 12, PHP 8.3, `inertiajs/inertia-laravel` ^2.0, `tightenco/ziggy` ^2, React 18 + TypeScript (strict) + Vite + Tailwind CSS, Mailtrap SMTP (`array` mailer in tests), Pest + `AssertableInertia`.

**Spec:** `docs/superpowers/specs/2026-08-20-onboarding-dashboard-design.md` (binding for this plan). Engine spec + `CLAUDE.md` apply wherever this spec is silent.

## Global Constraints

- Everything in `CLAUDE.md` (tenancy, 404-not-403 cross-tenant, DTOs, no secrets in output, explicit listeners, ULIDs, `composer check` pristine per task).
- **No password column, ever.** Magic-link tokens stored only as SHA-256 hashes; links single-use, 15-minute expiry, throttled 5/min per email and per IP; login responses identical whether or not the email exists.
- **No secret ever reaches Inertia props** (LHDN client secrets, certificate material/passphrases, API-key plaintext outside the one-time flash, webhook secrets outside the one-time flash, magic-link tokens). Every page touching a secret-bearing model gets an explicit prop-shape test.
- Web controllers contain **no domain logic**: authorize → call existing action → render/redirect. New logic goes in `app/Actions`/`app/Domain` (HTTP-free).
- Props for domain objects are always the existing `app/Data/Resources` DTOs — never ad-hoc model arrays.
- Validation errors via Inertia's error bag; `problem+json` stays API-only. Within-tenant role denial = 403 page; cross-tenant / cross-issuer (vendor) = 404.
- Every ID-carrying web route gets rows in the web section of `tests/Feature/TenantIsolationSweepTest.php`.
- Feature tests call `$this->withoutVite()` (wired once in `tests/Pest.php` for web tests) — no JS build in CI/tests. `composer check` remains the only merge gate.
- Frontend: TypeScript strict; components in `resources/js/Components`, pages in `resources/js/Pages`; one status-color mapping in `resources/js/lib/status.ts`; no UI-kit dependency.

## Execution notes (Windows worktrees)

- Real `vendor/` copies (robocopy) — never junctions. After any task adds composer deps, receiving worktrees run **full `composer install`** (never selective package copies).
- `node_modules` is NOT copied between worktrees; tasks that run `npm` do `npm install` in their own worktree (package-lock.json is committed in Task 1). Pest never needs built assets.
- Task 1 must merge before all others (toolchain). Waves after that: {2} → {3} → {4 ∥ 5 ∥ 6} → {7} → {8}.

---

### Task 1: Frontend toolchain + Inertia scaffold

**Files:**
- Modify: `composer.json` (require `inertiajs/inertia-laravel:^2.0`, `tightenco/ziggy:^2.0`), `package.json`, `vite.config.ts`, `resources/views/app.blade.php` (root template), `bootstrap/app.php` (web middleware group appends `HandleInertiaRequests`), `.env.example`, `phpunit.xml` (`MAIL_MAILER=array` if not present), `tests/Pest.php` (`withoutVite()` for `tests/Feature/Web`)
- Create: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/app.tsx`, `resources/js/ssr-stub.d.ts` (none — no SSR), `resources/css/app.css`, `tsconfig.json`, `tailwind.config.js`, `resources/js/Layouts/AppLayout.tsx`, `resources/js/Layouts/GuestLayout.tsx`, `resources/js/Components/{Button,Card,FormField,StatusBadge,ConfirmDialog,SecretOnceModal,DataTable,Pagination,FlashToast}.tsx`, `resources/js/lib/status.ts`, `resources/js/types/index.d.ts` (SharedProps, DTO interfaces), `resources/js/Pages/Errors/{Forbidden,NotFound}.tsx` + Laravel exception rendering for Inertia (403/404/419/500 render Inertia error pages on web routes), `tests/Feature/Web/InertiaScaffoldTest.php`

**Interfaces:**
- Produces: `HandleInertiaRequests::share()` returning exactly `{auth: {user: {id,name,email,role,issuer_id}|null}, tenant: {name}|null, environment: 'sandbox'|'production'|null, flash: {success: string|null, secret: string|null}}` (user/tenant/environment null until Task 2/3 wire them — share() reads `$request->user()` and `session('environment')` defensively so this task ships standalone).
- Produces: `AppLayout` (sidebar nav slots, env badge placeholder, user menu placeholder), `GuestLayout` (centered card), the component set above, `status.ts` exporting `statusColor(kind: 'document'|'issuer'|'delivery', value: string): 'gray'|'blue'|'green'|'amber'|'red'`.
- Root view `app.blade.php` uses `@vite(['resources/js/app.tsx'])` + `@inertiaHead` + `@routes` (Ziggy).
- Test bootstrap: `pest()` group for `tests/Feature/Web` runs `$this->withoutVite()`.

- [ ] **Step 1: Failing test** `InertiaScaffoldTest.php`: a temporary route rendering `Inertia::render('Errors/NotFound')` returns 200 with `X-Inertia` component assertion via `AssertableInertia` (`->component('Errors/NotFound')`); shared props contain `flash` keys.
- [ ] **Step 2: Run to verify failure** (`php vendor/bin/pest tests/Feature/Web`) — fails: package missing.
- [ ] **Step 3: Install + scaffold** — `composer require inertiajs/inertia-laravel tightenco/ziggy`; `npm install` deps (`@inertiajs/react react react-dom typescript @types/react @types/react-dom @vitejs/plugin-react tailwindcss @tailwindcss/vite laravel-vite-plugin vite`); write all files above. `npm run build` must succeed locally once (proves TS strict + Vite config), but tests never require it.
- [ ] **Step 4: Run** — scaffold test green; whole suite green (`composer check`).
- [ ] **Step 5: Commit** — `feat(web): Inertia + React + TypeScript toolchain, layouts, shared components`

---

### Task 2: Users, magic-link auth, invites

**Files:**
- Create: `database/migrations/2026_08_23_000001_create_users_table.php`, `database/migrations/2026_08_23_000002_create_login_tokens_table.php`, `app/Models/User.php`, `app/Models/LoginToken.php`, `app/Enums/UserRole.php`, `app/Auth/SendLoginLink.php`, `app/Auth/ConsumeLoginToken.php`, `app/Auth/InviteUser.php`, `app/Mail/LoginLinkMail.php` (+ `resources/views/mail/login-link.blade.php`), `app/Http/Controllers/Web/AuthController.php`, `resources/js/Pages/Auth/{Login,LinkSent,LinkInvalid}.tsx`, `app/Console/Commands/InviteUser.php` (`einvoice:invite-user`), `database/factories/UserFactory.php`, `tests/Feature/Web/MagicLinkTest.php`, `tests/Feature/Web/InviteTest.php`
- Modify: `config/auth.php` (users provider → `App\Models\User`), `routes/web.php`, `.env.example` (Mailtrap SMTP block), `app/Providers/AppServiceProvider.php` (rate limiters `login-link-email`, `login-link-ip`)

**Interfaces:**
- Consumes: `Tenant`, `Issuer`, `AuditLogger` (existing).
- Produces:
  - `users`: ulid PK, `tenant_id` FK, `name`, `email` string(191) **unique (global)**, `role` string enum-cast `UserRole {Owner='owner', Member='member', Vendor='vendor'}`, `issuer_id` nullable FK, `invited_at` timestamp, `last_login_at` nullable, timestamps; index `(tenant_id, role)`; DB CHECK `(role = 'vendor') = (issuer_id is not null)` (SQLite + MySQL compatible: `CHECK ((role = 'vendor' AND issuer_id IS NOT NULL) OR (role <> 'vendor' AND issuer_id IS NULL))`).
  - `App\Models\User extends Authenticatable`: `HasUlids`, `BelongsToTenant`, `Notifiable` NOT used (mail direct); helpers `isOwner()`, `isMember()`, `isVendor()`, `isActive(): bool` (= `last_login_at !== null`); `$hidden = []` but NO secret columns exist.
  - `login_tokens`: ulid PK, `user_id` FK cascade, `token_hash` char(64) unique, `expires_at`, `consumed_at` nullable, `created_at`. Model `$hidden = ['token_hash']`.
  - `SendLoginLink::handle(string $email): void` — always returns void; if a user matches, creates a token (plaintext = 64-char random, store sha256, expiry `now()+15min`), mails `LoginLinkMail` with `URL::signedRoute`-style absolute link `route('login.consume', $plaintext)`; audits `user.login_link_sent` (no token in audit).
  - `ConsumeLoginToken::handle(string $plaintext): ?User` — hash lookup; null when missing/expired/consumed; on success marks consumed, invalidates the user's other outstanding tokens, sets `last_login_at`, audits `user.logged_in`, returns the user.
  - `InviteUser::handle(Tenant $tenant, string $email, string $name, UserRole $role, ?Issuer $issuer): User` — validates vendor⇔issuer pairing, global email uniqueness (`ValidationException` on collision), creates user (`invited_at=now()`), sends login link, audits `user.invited`. Re-invite = `SendLoginLink` again.
  - Routes: `GET /login` (guest), `POST /login/link` (throttle:`login-link-email`,`login-link-ip`), `GET /login/{token}` → consume (success → intended or `/dashboard`; failure → `LinkInvalid` page, HTTP 200), `POST /logout`.
  - Session regeneration on login (`$request->session()->regenerate()`).
  - Command: `einvoice:invite-user {tenant} {email} --name= --role=owner --issuer=` → `InviteUser`.
- Tests (write first, table where sensible): link request for existing vs unknown email returns identical page + status (assert **byte-identical** Inertia component + props minus CSRF); mail sent only for existing (array mailer, assert link present + token not logged); consume happy path logs in, regenerates session id, sets `last_login_at`, invalidates sibling tokens; expired/consumed/tampered → `LinkInvalid`, not logged in; throttle 6th request 429; DB stores only hash (`assertDatabaseMissing` plaintext); CHECK constraint (vendor without issuer throws QueryException); invite collision → validation error; command creates+mails.

- [ ] **Step 1: Write failing tests** (`MagicLinkTest`, `InviteTest`) per the list.
- [ ] **Step 2: Run to verify failure.**
- [ ] **Step 3: Implement** per Interfaces (auth guard `web` unchanged; provider model swap).
- [ ] **Step 4: Run** — new tests + whole suite green; `composer check`.
- [ ] **Step 5: Commit** — `feat(auth): passwordless users with magic links and invites`

---

### Task 3: Dashboard context middleware, policies, error pages, overview, web sweep harness

**Files:**
- Create: `app/Http/Middleware/EnsureDashboardTenantContext.php`, `app/Policies/{IssuerPolicy,DocumentPolicy,WebhookEndpointPolicy,ApiKeyPolicy,UserAdminPolicy}.php`, `app/Http/Controllers/Web/{DashboardController,EnvironmentController}.php`, `resources/js/Pages/Dashboard/Overview.tsx`, `tests/Feature/Web/{DashboardContextTest,RoleMatrixTest}.php`
- Modify: `routes/web.php` (authed group: `auth`, `EnsureDashboardTenantContext`), `app/Providers/AppServiceProvider.php` (Gate::policy registrations — explicit, mirroring listener style), `HandleInertiaRequests` (share real `auth.user`, `tenant.name`, `environment`), `tests/Feature/TenantIsolationSweepTest.php` (new `web_cross_tenant_routes` dataset + executor logging in as an owner of tenant A hitting tenant B's ids → 404; rows added by Tasks 4–7 as routes appear), `bootstrap/app.php` (web exceptions → Inertia error pages for 403/404/419)

**Interfaces:**
- Produces:
  - `EnsureDashboardTenantContext`: binds `TenantContext` (`user->tenant`, environment from `session('environment','sandbox')` cast to `Environment`); clears on terminate. Runs before `SubstituteBindings` (explicit middleware priority as the API does).
  - `POST /environment {environment: sandbox|production}` → validates, stores in session, redirects back. Any role.
  - `GET /dashboard`: counts by document status (env-scoped, vendor-filtered), 10 most recent documents (`DocumentData::collect`), certificate expiry warnings (issuers with `certificate_valid_until < now()+30d`), pending-vendor count for owner/member.
  - Policies (used by Tasks 4–7): `IssuerPolicy@view/update` owner|member any issuer, vendor only `issuer_id === user->issuer_id` (deny → 404 via `denyAsNotFound()` for vendors); `@goLive` owner only. `DocumentPolicy@view` same vendor scoping (not-found style). `WebhookEndpointPolicy@manage` owner|member. `ApiKeyPolicy@manage` owner. `UserAdminPolicy@manageTeam` owner; `@manageVendors` owner|member.
  - Sweep executor: helper `actingAsDashboardUser(User $user)` in `tests/Feature/Web/helpers.php` (binds session env + login).
- Tests: unauthenticated `/dashboard` redirects to `/login`; environment switch persists and re-binds context (assert an env-scoped listing changes); vendor visiting `/dashboard` sees only own-issuer counts (fixture: two issuers, docs in both); policy unit-style tests via Gate for the full role matrix table; 403 renders the Inertia `Errors/Forbidden` page; sweep harness runs with an initial row (dashboard itself is ID-free; harness proves the mechanism with a fake id route from Task 4 placeholder — acceptable to land harness with `todo` dataset guarded `->skip()` until Task 4 merges IF no ID route exists yet; prefer wiring one real row in Task 4's merge).

- [ ] **Step 1: Failing tests** (`DashboardContextTest`, `RoleMatrixTest`).
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — suite green; `composer check`.
- [ ] **Step 5: Commit** — `feat(web): dashboard tenant context, role policies, overview page, web isolation harness`

---

### Task 4: IssuerOnboardingState + issuer wizard

**Files:**
- Create: `app/Domain/Onboarding/IssuerOnboardingState.php`, `app/Domain/Onboarding/StepState.php`, `app/Actions/Documents/CreateSandboxTestDocument.php`, `app/Http/Controllers/Web/{IssuerController,IssuerWizardController}.php`, `resources/js/Pages/Issuers/{Index,Wizard}.tsx` (wizard renders per-step subcomponents `resources/js/Pages/Issuers/Steps/{Profile,VerifyTin,Mode,Certificate,SandboxTest,GoLive}.tsx`), `tests/Unit/IssuerOnboardingStateTest.php`, `tests/Feature/Web/IssuerWizardTest.php`
- Modify: `routes/web.php`, `tests/Feature/TenantIsolationSweepTest.php` (rows: issuer wizard GET + every step POST, cross-tenant AND vendor-cross-issuer)

**Interfaces:**
- Consumes: existing actions — issuer update path used by `PATCH /v1/issuers/{id}` (reuse the same action/DTO), `VerifyIssuerTin`, secret/consent storage used by the API's mode endpoints, certificate upload action, `CreateDocument`, `AuthorizeIssuer`, `IssuerActivator`, `CertificateMetaData`, `IssuerData`.
- Produces:
  - `StepState { public string $key; public bool $done; public ?string $blocked_reason; }`
  - `IssuerOnboardingState::for(Issuer $issuer, Environment $env): self`; `->steps(): list<StepState>` (keys exactly: `profile`, `tin`, `mode`, `certificate`, `sandbox_test`, `go_live`); `->current(): string` (first not-done). Derivations (table-driven test): `profile` done = required profile fields non-null; `tin` done = tin_verified flag/timestamp set; `mode` done = for `$env`: consent recorded OR own credentials stored; `certificate` done = active secret has cert meta for `$env`; `sandbox_test` done = a `valid` document exists for issuer in sandbox with `source_system='dashboard-test'`; `go_live` done = issuer `active` in production. `blocked_reason` for `go_live` when preceding incomplete or actor not owner.
  - `CreateSandboxTestDocument::handle(Issuer $issuer, User $actor): Document` — fixed template: type invoice, currency MYR, buyer = general-public test buyer (name "Sandbox Test Buyer", TIN `EI00000000010`), one line (description "Dashboard sandbox test", qty 1, unit price 10.00, classification from seeded reference data, tax exempt), `source_system='dashboard-test'`, `source_ref='dashtest-'.Str::ulid()`, `submit=true`, `consolidate=false`. Runs in **sandbox context regardless of session env** (controller guards: step visible/postable only when session env is sandbox).
  - Routes as spec §8: `GET/POST /issuers`, `GET /issuers/{issuer}/onboarding` (props: `IssuerData`, `steps`, `current`), `PATCH profile`, `POST verify-tin`, `POST mode`, `POST certificate`, `POST sandbox-test` (returns redirect; page polls document status via partial reload of a `test_document` prop = `DocumentData|null` latest dashboard-test doc), `POST go-live` (owner; runs production authorize/activation; on success flash + redirect).
- Tests: unit table for every step derivation (fixtures per state); feature — vendor can complete steps 1–5 on own issuer, 404 on another issuer; member cannot go-live (403), owner can (uses `FakeLhdnClient`); sandbox-test creates+submits doc and wizard shows `valid` after fake settles; production env hides/blocks sandbox-test POST (403 with explanatory error); prop-shape test: wizard props never include client_secret/passphrase/cert material (walk the JSON of the rendered page); sweep rows.

- [ ] **Step 1: Failing tests** (unit table first, then feature).
- [ ] **Step 2: Verify failure.**
- [ ] **Step 3: Implement** (controllers stay thin — every mutation delegates to the existing action; only `IssuerOnboardingState` + `CreateSandboxTestDocument` are new logic).
- [ ] **Step 4: Run** — suite + `composer check`.
- [ ] **Step 5: Commit** — `feat(web): issuer onboarding wizard with derived state and sandbox test`

---

### Task 5: Document browser

**Files:**
- Create: `app/Http/Controllers/Web/DocumentBrowserController.php`, `app/Data/Requests/Web/DocumentFilterData.php`, `resources/js/Pages/Documents/{Index,Show}.tsx`, `tests/Feature/Web/DocumentBrowserTest.php`
- Modify: `routes/web.php`, sweep rows (documents show/pdf/cancel/resubmit; vendor scoping rows)

**Interfaces:**
- Consumes: `DocumentData`, `DocumentEventData` (or events via existing serialization), `DocumentPdfGenerator`, cancel/resubmit actions (`CancelDocument`, `SubmitDocument` — read exact names from `routes/api.php` controllers), `SubmissionAttempt` (redacted response excerpts for the error panel).
- Produces:
  - `GET /documents` — filters `status[], type[], issuer_id, from, to, q` (matches `source_ref` prefix), cursor pagination (same `CursorPaginatedDataCollection` the API uses), vendor auto-scoped.
  - `GET /documents/{document}` — props: `document: DocumentData`, `events: list` (asc), `errors: list<{code,message,target}>` parsed from the latest invalid/rejected event payload + latest attempt (already redacted at rest — assert no auth headers in props), `consolidation: {parent: DocumentData|null, children_count: int}`, `abilities: {can_cancel: bool, can_resubmit: bool, can_pdf: bool}` computed from the state machine's legal transitions (single source: a small `DocumentAbilities::for(Document)` helper in `app/Domain/Documents` reading `DocumentStateMachine` — no duplicated state lists in the controller).
  - `GET /documents/{document}/pdf` (valid only, else 404-from-policy? No: 409 matches API? For web UX: button hidden when not valid; direct hit returns 403-style Inertia error page? **Ruling: reuse API semantics — 409 problem page is API-only; web renders the `Errors/NotFound` page for non-valid PDFs** to avoid a new error surface; record in ledger), `POST cancel {reason}`, `POST resubmit`.
- Tests: list filters (status/type/date/q), pagination cursor follows, vendor sees only own issuer (list + direct id → 404), events ordering, abilities matrix (table-driven over statuses), cancel happy + outside-window error rendered in error bag, resubmit from `invalid`, PDF served for valid (binary response headers) and NotFound page otherwise, prop-shape secret scan.

- [ ] **Steps 1–5:** failing tests → verify → implement → suite + `composer check` → commit `feat(web): document browser with detail, actions and PDF`

---

### Task 6: Webhooks + API keys pages

**Files:**
- Create: `app/Http/Controllers/Web/{WebhookPageController,ApiKeyPageController}.php`, `resources/js/Pages/Webhooks/{Index,Deliveries}.tsx`, `resources/js/Pages/ApiKeys/Index.tsx`, `tests/Feature/Web/{WebhookPagesTest,ApiKeyPagesTest}.php`
- Modify: `routes/web.php`, sweep rows (webhook show-ish routes, deliveries, redeliver, api-key delete)

**Interfaces:**
- Consumes: the exact actions the API's `WebhookEndpointController`/`WebhookDeliveryController` use (extract to `app/Actions/Webhooks/*` **only if** logic currently lives in the API controller — if so, move it verbatim into actions and re-point the API controller in the same commit; API tests must stay green unchanged), `PublicHttpsUrl`, `WebhookEndpointData`, `WebhookDeliveryData`, `ApiKey` issuance/revocation code paths (same rule — extract if needed), `WebhookEvent::values()`.
- Produces: routes per spec §8. Create flows put the one-time plaintext (webhook secret / API key) in `flash.secret` — rendered exactly once by `SecretOnceModal`; a reload shows nothing. Deliveries page: per-endpoint log with status/attempt/http_status/snippet/next_retry + redeliver + test buttons.
- Tests: role matrix (member manages webhooks, member blocked from API keys 403, vendor blocked 403), URL validation error rendered via error bag (private IP), one-time secret in flash then gone on reload, revoke + revoked state rendering, redeliver creates new delivery (reuse existing fakes), env scoping (endpoint created in sandbox invisible after switching to production), prop-shape scans (webhook `secret` and key hash never in props), sweep rows.

- [ ] **Steps 1–5:** failing tests → verify → implement → suite + `composer check` → commit `feat(web): webhook and API key management pages`

---

### Task 7: Team + vendor onboarding

**Files:**
- Create: `app/Http/Controllers/Web/{TeamController,VendorController}.php`, `app/Actions/Onboarding/InviteVendor.php`, `resources/js/Pages/Team/Index.tsx`, `resources/js/Pages/Vendors/Index.tsx`, `tests/Feature/Web/{TeamTest,VendorOnboardingTest}.php`
- Modify: `routes/web.php`, sweep rows (`/team/{user}` deletes/reinvites, `/vendors/{user}/reinvite`)

**Interfaces:**
- Consumes: `InviteUser` (Task 2), `IssuerOnboardingState` (Task 4), `IssuerData`, `UserRole`.
- Produces:
  - `InviteVendor::handle(Tenant $tenant, string $email, string $vendorName, ?Issuer $existing): User` — creates issuer shell (name only, sandbox defaults) when `$existing` null; guards `$existing` not already onboarded-with-a-vendor-user; delegates to `InviteUser(role: Vendor, issuer: …)`.
  - `GET /team` (owner): users list (never vendors) with role, invited/active, last login; `POST /team/invites {email,name,role in owner|member}`; `POST /team/{user}/reinvite`; `DELETE /team/{user}` (cannot delete self; cannot delete last owner — validation errors).
  - `GET /vendors` (owner/member): progress board — per vendor issuer: vendor name, user email + invited/active, `current` step key + per-step done flags from `IssuerOnboardingState` (session env), issuer status badge, `last_activity` (issuer `updated_at`), reinvite button. `POST /vendors/invites {email, vendor_name, issuer_id?}`; `POST /vendors/{user}/reinvite`.
- Tests: invite member (mail sent, row invited), reinvite resends, delete guards (self, last owner), member blocked from `/team` 403; vendor invite creates issuer shell + pinned user (CHECK satisfied), invite to existing issuer, collision error; board shows correct step for staged fixtures (shell → profile-done → cert-done → active), vendor blocked from `/team` and `/vendors` (403), cross-tenant 404 sweep rows, prop scan (no tokens/secrets).

- [ ] **Steps 1–5:** failing tests → verify → implement → suite + `composer check` → commit `feat(web): team management and marketplace vendor onboarding board`

---

### Task 8: Docs, sweep audit, README

**Files:**
- Modify: `docs/superpowers/specs/2026-08-20-onboarding-dashboard-design.md` (as-built amendments only, dated), `README.md` (Dashboard section: local dev `npm run dev`, magic-link flow, invite command, env switcher; screenshots optional/skip), `docs/lhdn-gateway.md` (only if wizard touches documented flows), `.env.example` (verify Mailtrap + Vite vars complete), `tests/Feature/TenantIsolationSweepTest.php` (audit: `php artisan route:list` web routes vs sweep + role-matrix coverage; add missing rows)
- Verify: every doc claim against code (Plan 3/4 lesson: no aspirational docs). No `Plan 5 outcome` roadmap section (controller writes it post-review).

- [ ] **Step 1: Edits** (read targets first). **Step 2: Route audit** vs sweep datasets. **Step 3: `composer check`** green. **Step 4: Commit** — `docs: dashboard guide, spec as-built notes, web sweep audit`

---

## Plan self-review (done at authoring time)

- **Spec coverage:** §3 architecture → Tasks 1, 3; §4.1–4.3 users/magic links/invites → Task 2; §4.4 env switcher → Task 3; §5 matrix → Task 3 (policies) + per-feature tests in 4–7; §6.1 wizard → Task 4; §6.2 keys → Task 6; §6.3 webhooks → Task 6; §6.4 browser → Task 5; §6.5 vendors → Task 7; §7 stack/conventions → Task 1; §8 routes → Tasks 2–7 as listed; §9 security → global constraints + per-task prop-scan tests; §10 testing → embedded per task.
- **Placeholder scan:** none — each task names exact files, schemas, signatures, route lists, and test lists; representative code is given where behaviour is novel (state derivation keys, sandbox-test template, CHECK constraint) and everything else names the existing pattern to mirror file-by-file. One deliberate in-plan ruling (non-valid PDF → NotFound page) is flagged for the ledger.
- **Type consistency:** `IssuerOnboardingState::for(Issuer, Environment)` + `StepState` used by Tasks 4 and 7; `InviteUser::handle(Tenant, string, string, UserRole, ?Issuer)` used by Tasks 2 and 7; `flash.secret` contract shared by Tasks 1 (modal) and 6 (producers); `statusColor` kinds match Tasks 4–7 usage; sweep helper `actingAsDashboardUser` from Task 3 used by 4–7.
