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

See `CLAUDE.md` for guardrails (tenancy, auth, testing, and security rules).
