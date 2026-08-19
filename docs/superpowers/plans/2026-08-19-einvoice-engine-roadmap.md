# E-Invoice Engine — Implementation Roadmap

**Spec:** `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md`

The spec is one service but four subsystems. Each plan below produces working, testable software on its own and is executed in order. Only Plan 1 is written in full so far; each subsequent plan is written (with the writing-plans skill) once the previous one is merged, so it can build on the real code.

| # | Plan | Spec sections | Deliverable |
|---|------|---------------|-------------|
| 1 | `2026-08-19-plan-1-foundation.md` | 2, 3, 4, 7.1, 7.5, 8 (tenants, api-keys, issuers, buyers, reference), 9, 10, 11 | Running API with tenancy, auth, issuers + secrets/certs, buyers, reference data, audit, problem+json errors, isolation test suite |
| 2 | Plan 2 — Documents core | 5.1–5.5, 8 (documents create/batch/get/events), 9 | `DocumentData` DTO, validation & totals, `documents`/`document_lines`/`document_events` tables, `DocumentStateMachine`, create/batch/get/events endpoints, idempotency (natural key + `Idempotency-Key`), `held` logic for inactive issuers, domain events (no LHDN yet — documents stop at `queued`) |
| 3 | Plan 3 — LHDN gateway | 4.4, 6.1–6.5, 8 (verify-tin, authorize, tin/validate, submit, cancel) | `LhdnClient` interface + Intermediary/OwnCredentials/Fake clients, token cache, UBL 2.1 builder (golden-file tests), Signer, `PrepareDocument`/`SubmissionBatcher`/`SubmissionPoller` jobs, rate limiter + circuit breaker, cancel & rejection, issuer authorize flow, opt-in sandbox integration tests |
| 4 | Plan 4 — Consolidation, webhooks, PDF, cert lifecycle | 5.6, 7.2, 7.3, 7.4, 8 (webhooks, pdf, redeliver) | Monthly consolidation job, webhook endpoints + signed delivery + redeliver, PDF/QR rendering, cert expiry monitor + suspension/release, ops alerts |
| 5 | Plan 5 — Onboarding dashboard (UI/UX) | 13.2 (added 2026-08-19 at user request) | Merchant-facing web UI for self-service onboarding: sign-in via Billplz account, issuer wizard (business profile → TIN verify → LHDN access mode → intermediary consent / own credentials → certificate upload → sandbox test → go live), vendor onboarding for marketplaces (invite + progress tracking), API key management, document browser with status/errors, webhook setup. Front end: **Inertia.js + React** (user decision 2026-08-19), served by the engine app. Gets its own brainstorm + spec before planning (auth/SSO with Billplz accounts, UX flows, empty/error states, design system). |

Follow-ups outside this repo (spec §13): SDK, portal, Catalog/Recurring/Affiliates integrations.
