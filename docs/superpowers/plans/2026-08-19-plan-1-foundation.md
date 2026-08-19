# E-Invoice Engine — Plan 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the multi-tenant e-invoice engine API skeleton: Laravel 12 project, row-level tenancy, dual authentication (service tokens + API keys), tenants, issuers with encrypted secrets and signing certificates, buyers, reference data, audit logging, RFC 7807 errors, and a tenant-isolation test suite.

**Architecture:** Single Laravel 12 app. `TenantContext` (request-scoped singleton) is bound by `AuthenticateApi` middleware from either a service token (+ `X-Tenant-Id`) or an API key; every tenant-owned model uses the `BelongsToTenant` trait (global scope + auto-fill), so cross-tenant reads are impossible by construction. Controllers stay thin; domain logic sits in `app/Actions` and `app/Services`. All non-2xx responses are `application/problem+json`.

**Tech Stack:** PHP 8.4 (local) / 8.3+ (target), Laravel 12, Pest 3, Larastan, Pint, SQLite in-memory for tests, MySQL 8 + Redis via Docker for local dev, ULID primary keys, Laravel `encrypted` casts for secrets, PHP OpenSSL extension for certificate parsing.

**Spec:** `docs/superpowers/specs/2026-08-19-einvoice-engine-design.md` (sections 2, 3, 4, 7.1, 7.5, 8, 9, 10, 11). Roadmap: `docs/superpowers/plans/2026-08-19-einvoice-engine-roadmap.md`.

## Global Constraints

- Laravel `^12.0`, PHP `>=8.3`. Composer packages pinned by caret ranges only.
- Every tenant-owned table has `tenant_id` (`foreignUlid`), indexed, and part of any uniqueness constraint.
- Primary keys are ULIDs (`HasUlids`) on every model.
- API base path is `/v1`; every response body is JSON; every error is `application/problem+json` shaped `{type,title,status,detail,code?,errors?:[{pointer,code,message}]}`.
- Not-found across tenants is **404, never 403**.
- API keys: `ek_test_…` / `ek_live_…`, stored as SHA-256 hash, plaintext shown once. Service tokens: `sk_<service>_…`, stored hashed.
- Abilities: `read`, `documents:write`, `issuers:manage`, `webhooks:manage`, `tenants:manage` (service only), `*` (service only).
- Environments: `sandbox` | `production`. `ek_test_` keys only touch `sandbox` issuers, `ek_live_` only `production`. Service tokens choose via `X-Environment` (default `production`).
- Secret material (LHDN credentials, certificates, private keys, passphrases) is stored with Laravel `encrypted` casts and never serialised into API responses.
- Timezone for business dates: `Asia/Kuala_Lumpur`; DB timestamps in UTC.
- Tests: Pest, `php artisan test`. Static: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 8). Both must pass before every commit.
- Commit after every task with a conventional-commit message.
- Windows note: the shell is Git Bash. Use forward slashes; `php artisan …` and `vendor/bin/pest` work as written.

---

## File structure (created across the tasks)

```
app/
  Auth/
    Actor.php                       value object: type, id, name, abilities
    ResolvedCredential.php          actor + tenant? + environment?
    CredentialResolver.php          bearer token → ResolvedCredential|null (service token or api key)
  Enums/
    Environment.php  IdType.php  IssuerStatus.php  LhdnMode.php
  Exceptions/
    ProblemException.php            throwable → problem+json
  Http/
    Controllers/Api/V1/
      HealthController.php  MeController.php  TenantController.php  ApiKeyController.php
      IssuerController.php  IssuerCredentialsController.php  IssuerCertificateController.php
      BuyerController.php  ReferenceController.php
    Middleware/
      AuthenticateApi.php  EnsureTenantContext.php  EnsureAbility.php
    Problem/ProblemResponse.php     builds problem+json from Throwable
    Requests/…                      FormRequests per endpoint
    Resources/…                     JsonResources per model
  Models/
    Tenant.php  ServiceToken.php  ApiKey.php  Issuer.php  IssuerSecret.php
    IssuerSecretHistory.php  Buyer.php  ReferenceCode.php  AuditLog.php
  Services/
    Certificates/CertificateParser.php   PEM/PKCS12 → CertificateInfo
    Certificates/CertificateInfo.php
    Issuers/IssuerActivator.php          status evaluation rules
    Audit/AuditLogger.php
  Tenancy/
    TenantContext.php  TenantScope.php  BelongsToTenant.php  Exceptions/NoTenantContext.php
  Console/Commands/
    CreateServiceToken.php  RefreshReferenceData.php
database/
  migrations/…  factories/…  reference/*.json (seed data)
routes/api.php
tests/
  Pest.php (helpers)  Unit/…  Feature/…  Fixtures/certs/…
docker-compose.yml  phpstan.neon  .github/workflows/ci.yml
```

---

### Task 1: Scaffold the Laravel 12 project with Pest, Pint, Larastan and API routing

**Files:**
- Create: entire Laravel skeleton in repo root (via composer), `routes/api.php`, `phpstan.neon`, `docker-compose.yml`, `tests/Pest.php`
- Modify: `bootstrap/app.php`, `.env.example`, `composer.json` (scripts), `.gitignore`

**Interfaces:**
- Produces: `/v1` API prefix; `php artisan test` running Pest; `composer check` running pint + phpstan + tests.

- [ ] **Step 1: Create the skeleton in a temp dir and move it into the repo root**

```bash
cd "c:/Users/assya/OneDrive/Desktop/BillPlz/e-invoice"
composer create-project laravel/laravel tmp-app "^12.0" --prefer-dist --no-interaction
# move everything (including dotfiles) up one level, keeping our docs/ and .git/
mv tmp-app/* . && mv tmp-app/.editorconfig tmp-app/.env.example tmp-app/.gitattributes tmp-app/.gitignore . && rm -rf tmp-app
cp .env.example .env && php artisan key:generate
```

- [ ] **Step 2: Install dev tooling**

```bash
composer require pestphp/pest pestphp/pest-plugin-laravel larastan/larastan --dev --with-all-dependencies --no-interaction
php artisan pest:install --no-interaction
rm -f tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php
```

- [ ] **Step 3: Write `tests/Pest.php`** (replace generated content)

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');
```

- [ ] **Step 4: Configure API routing and middleware aliases in `bootstrap/app.php`**

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // aliases are added in Task 4
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // problem+json rendering is added in Task 4
    })->create();
```

- [ ] **Step 5: Create `routes/api.php` with a health route**

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']))->name('health');
```

- [ ] **Step 6: Create `phpstan.neon`**

```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    paths:
        - app
    level: 8
    checkMissingIterableValueType: false
```

- [ ] **Step 7: Add composer scripts** — in `composer.json` under `"scripts"` add:

```json
"lint": "vendor/bin/pint --test",
"analyse": "vendor/bin/phpstan analyse --memory-limit=1G",
"check": ["@lint", "@analyse", "@php artisan test"]
```

- [ ] **Step 8: Create `docker-compose.yml` for local MySQL + Redis**

```yaml
services:
  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: einvoice
      MYSQL_USER: einvoice
      MYSQL_PASSWORD: secret
    ports: ["3306:3306"]
    volumes: ["mysql-data:/var/lib/mysql"]
  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]
volumes:
  mysql-data:
```

- [ ] **Step 9: Set `.env.example` DB/cache/queue defaults**

Set these lines (edit in place):
```
APP_NAME="Billplz E-Invoice Engine"
APP_TIMEZONE=UTC
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=einvoice
DB_USERNAME=einvoice
DB_PASSWORD=secret
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
```
and `composer require predis/predis --no-interaction`. Copy the same values into `.env`. (Tests use `phpunit.xml`, which already forces `sqlite :memory:`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`.)

- [ ] **Step 10: Write a health test `tests/Feature/HealthTest.php`**

```php
<?php

it('reports healthy', function () {
    $this->getJson('/v1/health')->assertOk()->assertJson(['status' => 'ok']);
});
```

- [ ] **Step 11: Run the checks**

Run: `composer check`
Expected: pint passes, phpstan reports no errors, 1 test passes.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "chore: scaffold Laravel 12 engine with Pest, Pint, Larastan, /v1 routing"
```

---

### Task 2: Tenant model, TenantContext and BelongsToTenant

**Files:**
- Create: `app/Enums/Environment.php`, `app/Models/Tenant.php`, `database/migrations/2026_08_19_000001_create_tenants_table.php`, `database/factories/TenantFactory.php`, `app/Tenancy/TenantContext.php`, `app/Tenancy/TenantScope.php`, `app/Tenancy/BelongsToTenant.php`, `app/Tenancy/Exceptions/NoTenantContext.php`, `tests/Unit/Tenancy/BelongsToTenantTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Produces:
  - `App\Enums\Environment { Sandbox = 'sandbox'; Production = 'production' }`
  - `App\Tenancy\TenantContext::bind(Tenant $tenant, ?Actor $actor, Environment $env): void`, `tenant(): Tenant` (throws `NoTenantContext`), `tenantOrNull(): ?Tenant`, `actor(): ?Actor`, `environment(): Environment`, `has(): bool`, `clear(): void`. (`Actor` is defined in Task 4; until then the parameter is typed `?object` — Task 4 tightens it.)
  - `App\Tenancy\BelongsToTenant` trait: global `TenantScope`, auto-fills `tenant_id`, `tenant()` BelongsTo relation.
  - `App\Models\Tenant` (`id` ULID, `name`, `billplz_account_id` nullable unique, `status` string default `active`).

- [ ] **Step 1: Write the failing tests `tests/Unit/Tenancy/BelongsToTenantTest.php`**

```php
<?php

use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\Exceptions\NoTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

class TenantScopedWidget extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'widgets';
    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('widgets', function ($table) {
        $table->ulid('id')->primary();
        $table->foreignUlid('tenant_id')->index();
        $table->string('name');
        $table->timestamps();
    });
    $this->a = Tenant::factory()->create(['name' => 'A']);
    $this->b = Tenant::factory()->create(['name' => 'B']);
    $this->context = app(TenantContext::class);
});

it('throws when creating a tenant-owned model without tenant context', function () {
    TenantScopedWidget::create(['name' => 'x']);
})->throws(NoTenantContext::class);

it('auto-fills tenant_id from the bound context', function () {
    $this->context->bind($this->a, null, Environment::Production);
    $w = TenantScopedWidget::create(['name' => 'x']);
    expect($w->tenant_id)->toBe($this->a->id)
        ->and($w->tenant->is($this->a))->toBeTrue();
});

it('scopes all queries to the bound tenant', function () {
    $this->context->bind($this->a, null, Environment::Production);
    TenantScopedWidget::create(['name' => 'a1']);
    $this->context->bind($this->b, null, Environment::Production);
    TenantScopedWidget::create(['name' => 'b1']);

    expect(TenantScopedWidget::pluck('name')->all())->toBe(['b1']);
    $this->context->bind($this->a, null, Environment::Production);
    expect(TenantScopedWidget::pluck('name')->all())->toBe(['a1']);
});

it('returns nothing when no context is bound (fail closed)', function () {
    $this->context->bind($this->a, null, Environment::Production);
    TenantScopedWidget::create(['name' => 'a1']);
    $this->context->clear();
    expect(TenantScopedWidget::count())->toBe(0);
});

it('can bypass the scope explicitly', function () {
    $this->context->bind($this->a, null, Environment::Production);
    TenantScopedWidget::create(['name' => 'a1']);
    $this->context->clear();
    expect(TenantScopedWidget::withoutGlobalScope(\App\Tenancy\TenantScope::class)->count())->toBe(1);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Unit/Tenancy`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create the enum, model, migration, factory**

`app/Enums/Environment.php`
```php
<?php

namespace App\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
```

`database/migrations/2026_08_19_000001_create_tenants_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('billplz_account_id')->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
```

`app/Models/Tenant.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'billplz_account_id', 'status'];
}
```

`database/factories/TenantFactory.php`
```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'billplz_account_id' => fake()->unique()->bothify('acct_########'),
            'status' => 'active',
        ];
    }
}
```

- [ ] **Step 4: Create the tenancy classes**

`app/Tenancy/Exceptions/NoTenantContext.php`
```php
<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

class NoTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No tenant context is bound for this operation.');
    }
}
```

`app/Tenancy/TenantContext.php`
```php
<?php

namespace App\Tenancy;

use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContext;

class TenantContext
{
    private ?Tenant $tenant = null;

    private ?object $actor = null;

    private Environment $environment = Environment::Production;

    public function bind(?Tenant $tenant, ?object $actor, Environment $environment): void
    {
        $this->tenant = $tenant;
        $this->actor = $actor;
        $this->environment = $environment;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->actor = null;
        $this->environment = Environment::Production;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new NoTenantContext;
    }

    public function tenantOrNull(): ?Tenant
    {
        return $this->tenant;
    }

    public function actor(): ?object
    {
        return $this->actor;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }
}
```

`app/Tenancy/TenantScope.php`
```php
<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        $column = $model->qualifyColumn('tenant_id');

        if ($context->has()) {
            $builder->where($column, $context->tenant()->getKey());
        } else {
            // Fail closed: no context → no rows.
            $builder->whereRaw('1 = 0');
        }
    }
}
```

`app/Tenancy/BelongsToTenant.php`
```php
<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @mixin Model */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }
            $context = app(TenantContext::class);
            if (! $context->has()) {
                throw new NoTenantContext;
            }
            $model->setAttribute('tenant_id', $context->tenant()->getKey());
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Step 5: Register `TenantContext` as a scoped singleton** — in `app/Providers/AppServiceProvider.php::register()`:

```php
$this->app->scoped(\App\Tenancy\TenantContext::class);
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Unit/Tenancy`
Expected: 5 passed.

- [ ] **Step 7: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(tenancy): tenant model, TenantContext, BelongsToTenant scope"
```

---

### Task 3: RFC 7807 problem+json errors

**Files:**
- Create: `app/Exceptions/ProblemException.php`, `app/Http/Problem/ProblemResponse.php`, `tests/Feature/ProblemJsonTest.php`
- Modify: `bootstrap/app.php` (`withExceptions`)

**Interfaces:**
- Produces:
  - `ProblemException::__construct(int $status, string $title, string $detail = '', ?string $code = null, array $errors = [])` with static helpers `notFound(string $detail = 'Resource not found')`, `unauthenticated(string $detail)`, `forbidden(string $detail)`, `conflict(string $detail, string $code)`, `badRequest(string $detail, string $code)`.
  - `ProblemResponse::fromThrowable(Throwable $e, Request $request): JsonResponse` — maps ValidationException→422 (`errors[]` with `pointer` `/field/path`, `code` = rule name, `message`), ModelNotFound/NotFoundHttp→404, AuthenticationException→401, AuthorizationException→403, HttpException→its status, ProblemException→as-is, others→500 (detail hidden unless `app.debug`).
  - Every problem body: `{type: "https://einvoice.billplz.com/problems/<code|status>", title, status, detail, code?, errors?}` with header `Content-Type: application/problem+json`.

- [ ] **Step 1: Write failing tests `tests/Feature/ProblemJsonTest.php`**

```php
<?php

use App\Exceptions\ProblemException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Route::middleware('api')->prefix('v1')->group(function () {
        Route::get('/_test/problem', fn () => throw ProblemException::conflict('Already there', 'duplicate'));
        Route::get('/_test/validation', fn () => throw ValidationException::withMessages(['lines.0.qty' => ['must be > 0']]));
        Route::get('/_test/boom', fn () => throw new RuntimeException('secret detail'));
    });
});

it('renders ProblemException as problem+json', function () {
    $this->getJson('/v1/_test/problem')
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'type' => 'https://einvoice.billplz.com/problems/duplicate',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => 'Already there',
            'code' => 'duplicate',
        ]);
});

it('renders validation errors with JSON pointers', function () {
    $this->getJson('/v1/_test/validation')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('errors.0.pointer', '/lines/0/qty')
        ->assertJsonPath('errors.0.message', 'must be > 0');
});

it('renders unknown routes as 404 problem', function () {
    $this->getJson('/v1/does-not-exist')->assertStatus(404)->assertJsonPath('status', 404);
});

it('hides internal error details when debug is off', function () {
    config(['app.debug' => false]);
    $this->withoutExceptionHandling(false);
    $this->getJson('/v1/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('title', 'Internal Server Error')
        ->assertJsonMissing(['detail' => 'secret detail']);
});
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ProblemJsonTest.php`
Expected: FAIL (class not found / wrong content type).

- [ ] **Step 3: Create `app/Exceptions/ProblemException.php`**

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class ProblemException extends RuntimeException
{
    /** @param array<int, array{pointer?: string, code?: string, message: string}> $errors */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $detail = '',
        public readonly ?string $problemCode = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($detail !== '' ? $detail : $title);
    }

    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return new self(404, 'Not Found', $detail, 'not_found');
    }

    public static function unauthenticated(string $detail = 'Authentication required.'): self
    {
        return new self(401, 'Unauthenticated', $detail, 'unauthenticated');
    }

    public static function forbidden(string $detail = 'Forbidden.'): self
    {
        return new self(403, 'Forbidden', $detail, 'forbidden');
    }

    public static function conflict(string $detail, string $code): self
    {
        return new self(409, 'Conflict', $detail, $code);
    }

    public static function badRequest(string $detail, string $code): self
    {
        return new self(400, 'Bad Request', $detail, $code);
    }
}
```

- [ ] **Step 4: Create `app/Http/Problem/ProblemResponse.php`**

```php
<?php

namespace App\Http\Problem;

use App\Exceptions\ProblemException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProblemResponse
{
    public const TYPE_BASE = 'https://einvoice.billplz.com/problems/';

    public static function fromThrowable(Throwable $e, Request $request): JsonResponse
    {
        [$status, $title, $detail, $code, $errors] = self::describe($e);

        $body = [
            'type' => self::TYPE_BASE.($code ?? (string) $status),
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ];
        if ($code !== null) {
            $body['code'] = $code;
        }
        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    /** @return array{0:int,1:string,2:string,3:?string,4:array<int,array<string,string>>} */
    private static function describe(Throwable $e): array
    {
        if ($e instanceof ProblemException) {
            return [$e->status, $e->title, $e->detail, $e->problemCode, $e->errors];
        }
        if ($e instanceof ValidationException) {
            $errors = [];
            foreach ($e->validator->failed() as $field => $rules) {
                $messages = $e->validator->errors()->get($field);
                foreach (array_keys($rules) as $i => $rule) {
                    $errors[] = [
                        'pointer' => '/'.str_replace('.', '/', $field),
                        'code' => strtolower(class_basename((string) $rule)),
                        'message' => $messages[$i] ?? ($messages[0] ?? 'Invalid.'),
                    ];
                }
            }
            if ($errors === []) { // withMessages() has no failed() rules
                foreach ($e->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['pointer' => '/'.str_replace('.', '/', $field), 'code' => 'invalid', 'message' => $message];
                    }
                }
            }

            return [422, 'Unprocessable Entity', 'The request failed validation.', 'validation_failed', $errors];
        }
        if ($e instanceof AuthenticationException) {
            return [401, 'Unauthenticated', 'Authentication required.', 'unauthenticated', []];
        }
        if ($e instanceof AuthorizationException) {
            return [403, 'Forbidden', $e->getMessage() ?: 'Forbidden.', 'forbidden', []];
        }
        if ($e instanceof ModelNotFoundException) {
            return [404, 'Not Found', 'Resource not found.', 'not_found', []];
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $e->getMessage() !== '' ? $e->getMessage() : $title;

            return [$status, $title, $detail, null, []];
        }

        $detail = config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.';

        return [500, 'Internal Server Error', $detail, 'internal_error', []];
    }
}
```

- [ ] **Step 5: Register in `bootstrap/app.php`**

```php
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->shouldRenderJsonWhen(fn (\Illuminate\Http\Request $request, \Throwable $e) => $request->is('v1/*') || $request->expectsJson());
    $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
        if ($request->is('v1/*') || $request->expectsJson()) {
            return \App\Http\Problem\ProblemResponse::fromThrowable($e, $request);
        }

        return null;
    });
})
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/ProblemJsonTest.php`
Expected: 4 passed.

- [ ] **Step 7: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(http): RFC 7807 problem+json error responses"
```

---

### Task 4: Service tokens, AuthenticateApi middleware, abilities, tenants endpoint

**Files:**
- Create: `app/Auth/Actor.php`, `app/Auth/ResolvedCredential.php`, `app/Auth/CredentialResolver.php`, `app/Models/ServiceToken.php`, `database/migrations/2026_08_19_000002_create_service_tokens_table.php`, `database/factories/ServiceTokenFactory.php`, `app/Console/Commands/CreateServiceToken.php`, `app/Http/Middleware/AuthenticateApi.php`, `app/Http/Middleware/EnsureTenantContext.php`, `app/Http/Middleware/EnsureAbility.php`, `app/Http/Controllers/Api/V1/MeController.php`, `app/Http/Controllers/Api/V1/TenantController.php`, `app/Http/Requests/StoreTenantRequest.php`, `app/Http/Resources/TenantResource.php`, `tests/Feature/Auth/ServiceTokenAuthTest.php`, `tests/Feature/TenantsTest.php`
- Modify: `bootstrap/app.php` (aliases), `routes/api.php`, `app/Tenancy/TenantContext.php` (type `Actor`), `tests/Pest.php` (helpers)

**Interfaces:**
- Produces:
  - `App\Auth\Actor { public string $type ('service'|'api_key'); public string $id; public string $name; /** @var string[] */ public array $abilities; hasAbility(string $ability): bool; label(): string  // "service:catalog" | "api_key:ek_test_AbCd" }`
  - `App\Auth\ResolvedCredential { Actor $actor; ?Tenant $tenant; ?Environment $environment; Closure $touch }`
  - `App\Auth\CredentialResolver::resolve(string $bearer): ?ResolvedCredential` — Task 5 adds the API-key branch.
  - `ServiceToken::generate(string $name, array $abilities = ['*']): array{token: ServiceToken, plaintext: string}`; plaintext `sk_<name>_<40 chars>`; `token_hash = hash('sha256', plaintext)`.
  - Middleware aliases: `auth.api`, `tenant`, `ability:<name>`.
  - Headers: `Authorization: Bearer …`, `X-Tenant-Id`, `X-Environment`.
  - Routes: `GET /v1/me`, `POST /v1/tenants`.
  - Test helpers in `tests/Pest.php`: `serviceToken(array $abilities = ['*']): string` (plaintext), `serviceHeaders(Tenant $tenant, string $env = 'production', array $abilities = ['*']): array`.

- [ ] **Step 1: Write failing tests**

`tests/Feature/Auth/ServiceTokenAuthTest.php`
```php
<?php

use App\Models\ServiceToken;
use App\Models\Tenant;

it('rejects requests without a bearer token', function () {
    $this->getJson('/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('rejects unknown tokens', function () {
    $this->withHeader('Authorization', 'Bearer sk_catalog_nope')->getJson('/v1/me')->assertStatus(401);
});

it('rejects revoked service tokens', function () {
    ['token' => $t, 'plaintext' => $plain] = ServiceToken::generate('catalog');
    $t->update(['revoked_at' => now()]);
    $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/v1/me')->assertStatus(401);
});

it('authenticates a service token and requires X-Tenant-Id on tenant routes', function () {
    $tenant = Tenant::factory()->create();
    $plain = serviceToken();

    $this->withHeader('Authorization', "Bearer {$plain}")
        ->getJson('/v1/me')
        ->assertStatus(400)
        ->assertJsonPath('code', 'tenant_header_required');

    $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Id' => $tenant->id])
        ->getJson('/v1/me')
        ->assertOk()
        ->assertJsonPath('data.actor.type', 'service')
        ->assertJsonPath('data.tenant.id', $tenant->id)
        ->assertJsonPath('data.environment', 'production');
});

it('returns 404 for an unknown X-Tenant-Id', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.serviceToken(), 'X-Tenant-Id' => '01J00000000000000000000000'])
        ->getJson('/v1/me')->assertStatus(404)->assertJsonPath('code', 'tenant_not_found');
});

it('honours X-Environment for service tokens', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->getJson('/v1/me')
        ->assertOk()->assertJsonPath('data.environment', 'sandbox');
    $this->withHeaders(serviceHeaders($tenant, 'staging'))->getJson('/v1/me')
        ->assertStatus(400)->assertJsonPath('code', 'invalid_environment');
});

it('enforces abilities', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, abilities: ['read']))
        ->postJson('/v1/tenants', ['name' => 'X'])
        ->assertStatus(403)->assertJsonPath('code', 'forbidden');
});

it('updates last_used_at', function () {
    $tenant = Tenant::factory()->create();
    ['token' => $t, 'plaintext' => $plain] = ServiceToken::generate('recurring');
    $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Id' => $tenant->id])->getJson('/v1/me')->assertOk();
    expect($t->fresh()->last_used_at)->not->toBeNull();
});
```

`tests/Feature/TenantsTest.php`
```php
<?php

use App\Models\Tenant;

it('creates a tenant with a service token that has tenants:manage', function () {
    $this->withHeader('Authorization', 'Bearer '.serviceToken(['tenants:manage']))
        ->postJson('/v1/tenants', ['name' => 'Acme Sdn Bhd', 'billplz_account_id' => 'acct_1'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Acme Sdn Bhd')
        ->assertJsonPath('data.billplz_account_id', 'acct_1');
    expect(Tenant::where('billplz_account_id', 'acct_1')->exists())->toBeTrue();
});

it('validates tenant creation', function () {
    $this->withHeader('Authorization', 'Bearer '.serviceToken())
        ->postJson('/v1/tenants', [])
        ->assertStatus(422)->assertJsonPath('errors.0.pointer', '/name');
});

it('rejects duplicate billplz_account_id', function () {
    Tenant::factory()->create(['billplz_account_id' => 'acct_dup']);
    $this->withHeader('Authorization', 'Bearer '.serviceToken())
        ->postJson('/v1/tenants', ['name' => 'B', 'billplz_account_id' => 'acct_dup'])
        ->assertStatus(422);
});
```

Add helpers to `tests/Pest.php` (append):
```php
use App\Models\ServiceToken;
use App\Models\Tenant;

function serviceToken(array $abilities = ['*']): string
{
    return ServiceToken::generate('test-'.bin2hex(random_bytes(3)), $abilities)['plaintext'];
}

/** @return array<string,string> */
function serviceHeaders(Tenant $tenant, string $env = 'production', array $abilities = ['*']): array
{
    return [
        'Authorization' => 'Bearer '.serviceToken($abilities),
        'X-Tenant-Id' => $tenant->id,
        'X-Environment' => $env,
    ];
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/Auth tests/Feature/TenantsTest.php`
Expected: FAIL.

- [ ] **Step 3: Auth value objects**

`app/Auth/Actor.php`
```php
<?php

namespace App\Auth;

final class Actor
{
    /** @param string[] $abilities */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $name,
        public readonly array $abilities,
    ) {}

    public function hasAbility(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function label(): string
    {
        return "{$this->type}:{$this->name}";
    }
}
```

`app/Auth/ResolvedCredential.php`
```php
<?php

namespace App\Auth;

use App\Enums\Environment;
use App\Models\Tenant;
use Closure;

final class ResolvedCredential
{
    public function __construct(
        public readonly Actor $actor,
        public readonly ?Tenant $tenant,
        public readonly ?Environment $environment,
        private readonly Closure $touch,
    ) {}

    public function touch(): void
    {
        ($this->touch)();
    }
}
```

`app/Auth/CredentialResolver.php`
```php
<?php

namespace App\Auth;

use App\Models\ServiceToken;

class CredentialResolver
{
    public function resolve(string $bearer): ?ResolvedCredential
    {
        if (str_starts_with($bearer, 'sk_')) {
            return $this->resolveServiceToken($bearer);
        }

        return null; // 'ek_' API keys are added in Task 5
    }

    private function resolveServiceToken(string $bearer): ?ResolvedCredential
    {
        $token = ServiceToken::query()
            ->where('token_hash', hash('sha256', $bearer))
            ->whereNull('revoked_at')
            ->first();
        if ($token === null) {
            return null;
        }

        return new ResolvedCredential(
            actor: new Actor('service', $token->id, $token->name, $token->abilities),
            tenant: null,
            environment: null,
            touch: fn () => $token->forceFill(['last_used_at' => now()])->saveQuietly(),
        );
    }
}
```

Tighten `TenantContext`: change `?object $actor` to `?\App\Auth\Actor $actor` in the property, `bind()` and `actor()`.

- [ ] **Step 4: ServiceToken model, migration, factory, command**

`database/migrations/2026_08_19_000002_create_service_tokens_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_tokens');
    }
};
```

`app/Models/ServiceToken.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServiceToken extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'token_hash', 'abilities', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  string[]  $abilities
     * @return array{token: self, plaintext: string}
     */
    public static function generate(string $name, array $abilities = ['*']): array
    {
        $plaintext = 'sk_'.Str::slug($name, '_').'_'.Str::random(40);
        $token = static::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plaintext),
            'abilities' => $abilities,
        ]);

        return ['token' => $token, 'plaintext' => $plaintext];
    }
}
```

`database/factories/ServiceTokenFactory.php`
```php
<?php

namespace Database\Factories;

use App\Models\ServiceToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceToken> */
class ServiceTokenFactory extends Factory
{
    protected $model = ServiceToken::class;

    public function definition(): array
    {
        return [
            'name' => 'svc-'.Str::lower(Str::random(6)),
            'token_hash' => hash('sha256', Str::random(40)),
            'abilities' => ['*'],
        ];
    }
}
```

`app/Console/Commands/CreateServiceToken.php`
```php
<?php

namespace App\Console\Commands;

use App\Models\ServiceToken;
use Illuminate\Console\Command;

class CreateServiceToken extends Command
{
    protected $signature = 'einvoice:service-token {name : Service name, e.g. catalog} {--ability=* : Abilities (default *)}';

    protected $description = 'Create a service token for an internal Billplz system. The plaintext is shown once.';

    public function handle(): int
    {
        $abilities = $this->option('ability') ?: ['*'];
        ['plaintext' => $plaintext] = ServiceToken::generate($this->argument('name'), $abilities);
        $this->info('Service token created. Store it now; it will not be shown again:');
        $this->line($plaintext);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Middleware**

`app/Http/Middleware/AuthenticateApi.php`
```php
<?php

namespace App\Http\Middleware;

use App\Auth\CredentialResolver;
use App\Enums\Environment;
use App\Exceptions\ProblemException;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApi
{
    public function __construct(
        private readonly CredentialResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            throw ProblemException::unauthenticated('Missing bearer token.');
        }

        $credential = $this->resolver->resolve($bearer);
        if ($credential === null) {
            throw ProblemException::unauthenticated('Invalid or revoked credential.');
        }

        $tenant = $credential->tenant;
        $environment = $credential->environment;

        if ($tenant === null) { // service token: tenant + environment come from headers
            $tenantId = $request->header('X-Tenant-Id');
            if ($tenantId !== null && $tenantId !== '') {
                $tenant = Tenant::query()->find($tenantId)
                    ?? throw new ProblemException(404, 'Not Found', 'Tenant not found.', 'tenant_not_found');
            }
            $envHeader = $request->header('X-Environment', Environment::Production->value);
            $environment = Environment::tryFrom((string) $envHeader)
                ?? throw ProblemException::badRequest('X-Environment must be "sandbox" or "production".', 'invalid_environment');
        }

        $this->context->bind($tenant, $credential->actor, $environment ?? Environment::Production);
        $credential->touch();

        return $next($request);
    }
}
```

`app/Http/Middleware/EnsureTenantContext.php`
```php
<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->has()) {
            throw ProblemException::badRequest('This endpoint requires a tenant. Service tokens must send X-Tenant-Id.', 'tenant_header_required');
        }

        return $next($request);
    }
}
```

`app/Http/Middleware/EnsureAbility.php`
```php
<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbility
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $actor = $this->context->actor();
        if ($actor === null || ! $actor->hasAbility($ability)) {
            throw ProblemException::forbidden("This credential lacks the '{$ability}' ability.");
        }

        return $next($request);
    }
}
```

Register aliases in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'auth.api' => \App\Http\Middleware\AuthenticateApi::class,
        'tenant' => \App\Http\Middleware\EnsureTenantContext::class,
        'ability' => \App\Http\Middleware\EnsureAbility::class,
    ]);
})
```

- [ ] **Step 6: Controllers, request, resource, routes**

`app/Http/Requests/StoreTenantRequest.php`
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'billplz_account_id' => ['nullable', 'string', 'max:64', 'unique:tenants,billplz_account_id'],
        ];
    }
}
```

`app/Http/Resources/TenantResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'billplz_account_id' => $this->billplz_account_id,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/TenantController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;

class TenantController extends Controller
{
    public function store(StoreTenantRequest $request): TenantResource
    {
        $tenant = Tenant::create($request->validated());

        return new TenantResource($tenant);
    }
}
```

`app/Http/Controllers/Api/V1/MeController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class MeController extends Controller
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $actor = $context->actor();

        return response()->json(['data' => [
            'actor' => $actor === null ? null : [
                'type' => $actor->type,
                'id' => $actor->id,
                'name' => $actor->name,
                'abilities' => $actor->abilities,
            ],
            'tenant' => (new TenantResource($context->tenant()))->resolve(),
            'environment' => $context->environment()->value,
        ]]);
    }
}
```

`routes/api.php` (replace)
```php
<?php

use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']))->name('health');

Route::middleware('auth.api')->group(function () {
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('ability:tenants:manage');

    Route::middleware('tenant')->group(function () {
        Route::get('/me', MeController::class);
    });
});
```

- [ ] **Step 7: Run tests**

Run: `vendor/bin/pest tests/Feature/Auth tests/Feature/TenantsTest.php`
Expected: all pass (11 tests).

- [ ] **Step 8: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(auth): service tokens, AuthenticateApi, abilities, tenants endpoint"
```

---

### Task 5: API keys (model, resolver branch, endpoints)

**Files:**
- Create: `app/Models/ApiKey.php`, `database/migrations/2026_08_19_000003_create_api_keys_table.php`, `database/factories/ApiKeyFactory.php`, `app/Http/Controllers/Api/V1/ApiKeyController.php`, `app/Http/Requests/StoreApiKeyRequest.php`, `app/Http/Resources/ApiKeyResource.php`, `tests/Feature/ApiKeysTest.php`
- Modify: `app/Auth/CredentialResolver.php`, `routes/api.php`, `tests/Pest.php`

**Interfaces:**
- Produces:
  - `ApiKey::generate(Tenant $tenant, string $name, Environment $env, array $abilities): array{key: ApiKey, plaintext: string}` — plaintext `ek_test_…`/`ek_live_…` (prefix + 40 random), `prefix` column = first 12 chars, `key_hash` = sha256.
  - `Actor` for keys: `type = 'api_key'`, `name = prefix`.
  - Routes: `POST /v1/api-keys`, `GET /v1/api-keys`, `DELETE /v1/api-keys/{apiKey}` (revoke), all `ability:issuers:manage`.
  - Test helper: `apiKeyHeaders(Tenant $tenant, string $env = 'sandbox', array $abilities = ['read','documents:write','issuers:manage','webhooks:manage']): array`.
  - Constant list of key abilities: `ApiKey::ABILITIES = ['read','documents:write','issuers:manage','webhooks:manage']`.

- [ ] **Step 1: Write failing tests `tests/Feature/ApiKeysTest.php`**

```php
<?php

use App\Enums\Environment;
use App\Models\ApiKey;
use App\Models\Tenant;

it('creates an api key and shows the plaintext once', function () {
    $tenant = Tenant::factory()->create();
    $res = $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'Shop backend', 'environment' => 'sandbox', 'abilities' => ['read', 'documents:write']])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Shop backend')
        ->assertJsonPath('data.environment', 'sandbox');
    $plain = $res->json('data.key');
    expect($plain)->toStartWith('ek_test_');
    expect(ApiKey::withoutGlobalScopes()->where('key_hash', hash('sha256', $plain))->exists())->toBeTrue();

    $list = $this->withHeaders(serviceHeaders($tenant))->getJson('/v1/api-keys')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('key')->and($list->json('data.0.prefix'))->toBe(substr($plain, 0, 12));
});

it('rejects unknown abilities and invalid environment', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'x', 'environment' => 'sandbox', 'abilities' => ['tenants:manage']])
        ->assertStatus(422);
    $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'x', 'environment' => 'qa', 'abilities' => ['read']])
        ->assertStatus(422);
});

it('authenticates with an api key bound to its tenant and environment', function () {
    $tenant = Tenant::factory()->create();
    ['plaintext' => $plain] = ApiKey::generate($tenant, 'k', Environment::Sandbox, ['read']);
    $this->withHeaders(['Authorization' => "Bearer {$plain}", 'X-Tenant-Id' => Tenant::factory()->create()->id])
        ->getJson('/v1/me')->assertOk()
        ->assertJsonPath('data.tenant.id', $tenant->id)   // header ignored
        ->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.actor.type', 'api_key');
});

it('cannot list another tenant\'s keys', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    ApiKey::generate($a, 'a-key', Environment::Sandbox, ['read']);
    $this->withHeaders(serviceHeaders($b))->getJson('/v1/api-keys')->assertOk()->assertJsonCount(0, 'data');
});

it('revokes a key so it no longer authenticates', function () {
    $tenant = Tenant::factory()->create();
    ['key' => $key, 'plaintext' => $plain] = ApiKey::generate($tenant, 'k', Environment::Production, ['read']);
    $this->withHeaders(serviceHeaders($tenant))->deleteJson("/v1/api-keys/{$key->id}")->assertNoContent();
    $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/v1/me')->assertStatus(401);
});

it('returns 404 when revoking another tenant\'s key', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    ['key' => $key] = ApiKey::generate($a, 'k', Environment::Production, ['read']);
    $this->withHeaders(serviceHeaders($b))->deleteJson("/v1/api-keys/{$key->id}")->assertStatus(404);
});

it('lets an api key with issuers:manage create more keys for its own tenant only', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/api-keys', ['name' => 'child', 'environment' => 'sandbox', 'abilities' => ['read']])
        ->assertCreated();
    expect(ApiKey::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(2);
});
```

Append helper to `tests/Pest.php`:
```php
use App\Enums\Environment;
use App\Models\ApiKey;

/** @return array<string,string> */
function apiKeyHeaders(Tenant $tenant, string $env = 'sandbox', array $abilities = ApiKey::ABILITIES): array
{
    ['plaintext' => $plain] = ApiKey::generate($tenant, 'test-key', Environment::from($env), $abilities);

    return ['Authorization' => 'Bearer '.$plain];
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/pest tests/Feature/ApiKeysTest.php` → FAIL.

- [ ] **Step 3: Migration, model, factory**

`database/migrations/2026_08_19_000003_create_api_keys_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 16);
            $table->string('key_hash', 64)->unique();
            $table->string('environment', 16);
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
```

`app/Models/ApiKey.php`
```php
<?php

namespace App\Models;

use App\Enums\Environment;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    public const ABILITIES = ['read', 'documents:write', 'issuers:manage', 'webhooks:manage'];

    protected $fillable = ['tenant_id', 'name', 'prefix', 'key_hash', 'environment', 'abilities', 'last_used_at', 'revoked_at'];

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  string[]  $abilities
     * @return array{key: self, plaintext: string}
     */
    public static function generate(Tenant $tenant, string $name, Environment $environment, array $abilities): array
    {
        $plaintext = ($environment === Environment::Production ? 'ek_live_' : 'ek_test_').Str::random(40);
        $key = static::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'prefix' => substr($plaintext, 0, 12),
            'key_hash' => hash('sha256', $plaintext),
            'environment' => $environment,
            'abilities' => array_values($abilities),
        ]);

        return ['key' => $key, 'plaintext' => $plaintext];
    }
}
```

`database/factories/ApiKeyFactory.php`
```php
<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ApiKey> */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    public function definition(): array
    {
        $plain = 'ek_test_'.Str::random(40);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'prefix' => substr($plain, 0, 12),
            'key_hash' => hash('sha256', $plain),
            'environment' => Environment::Sandbox,
            'abilities' => ApiKey::ABILITIES,
        ];
    }
}
```

- [ ] **Step 4: Resolver branch** — in `CredentialResolver::resolve()` replace the `return null;` line with:

```php
if (str_starts_with($bearer, 'ek_')) {
    return $this->resolveApiKey($bearer);
}

return null;
```
and add:
```php
private function resolveApiKey(string $bearer): ?ResolvedCredential
{
    $key = ApiKey::withoutGlobalScopes()
        ->with('tenant')
        ->where('key_hash', hash('sha256', $bearer))
        ->whereNull('revoked_at')
        ->first();
    if ($key === null) {
        return null;
    }

    return new ResolvedCredential(
        actor: new Actor('api_key', $key->id, $key->prefix, $key->abilities),
        tenant: $key->tenant,
        environment: $key->environment,
        touch: fn () => $key->forceFill(['last_used_at' => now()])->saveQuietly(),
    );
}
```
(add `use App\Models\ApiKey;`).

- [ ] **Step 5: Request, resource, controller, routes**

`app/Http/Requests/StoreApiKeyRequest.php`
```php
<?php

namespace App\Http\Requests;

use App\Enums\Environment;
use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'environment' => ['required', Rule::enum(Environment::class)],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(ApiKey::ABILITIES)],
        ];
    }
}
```

`app/Http/Resources/ApiKeyResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ApiKey */
class ApiKeyResource extends JsonResource
{
    public function __construct($resource, private readonly ?string $plaintext = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'prefix' => $this->prefix,
            'environment' => $this->environment->value,
            'abilities' => $this->abilities,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'key' => $this->plaintext,
        ], fn ($v) => $v !== null);
    }
}
```

`app/Http/Controllers/Api/V1/ApiKeyController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Environment;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApiKeyRequest;
use App\Http\Resources\ApiKeyResource;
use App\Models\ApiKey;
use App\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ApiKeyController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ApiKeyResource::collection(ApiKey::whereNull('revoked_at')->latest()->cursorPaginate(50));
    }

    public function store(StoreApiKeyRequest $request, TenantContext $context): ApiKeyResource
    {
        ['key' => $key, 'plaintext' => $plaintext] = ApiKey::generate(
            $context->tenant(),
            $request->string('name')->toString(),
            Environment::from($request->string('environment')->toString()),
            $request->array('abilities'),
        );

        return (new ApiKeyResource($key, $plaintext))->additional([]);
    }

    public function destroy(ApiKey $apiKey): Response
    {
        $apiKey->update(['revoked_at' => now()]);

        return response()->noContent();
    }
}
```
Note: `store()` returns 201 automatically because a freshly created model's `wasRecentlyCreated` is true.

Routes — inside the `tenant` group in `routes/api.php`:
```php
Route::middleware('ability:issuers:manage')->group(function () {
    Route::get('/api-keys', [ApiKeyController::class, 'index']);
    Route::post('/api-keys', [ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);
});
```

- [ ] **Step 6: Run tests**

Run: `vendor/bin/pest tests/Feature/ApiKeysTest.php tests/Feature/Auth` → all pass.

- [ ] **Step 7: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(auth): tenant-scoped API keys with abilities and environments"
```

---

### Task 6: Issuers (enums, model, CRUD, environment enforcement)

**Files:**
- Create: `app/Enums/IdType.php`, `app/Enums/IssuerStatus.php`, `app/Enums/LhdnMode.php`, `app/Models/Issuer.php`, `database/migrations/2026_08_19_000004_create_issuers_table.php`, `database/factories/IssuerFactory.php`, `app/Http/Requests/StoreIssuerRequest.php`, `app/Http/Requests/UpdateIssuerRequest.php`, `app/Http/Resources/IssuerResource.php`, `app/Http/Controllers/Api/V1/IssuerController.php`, `app/Services/Issuers/IssuerActivator.php`, `tests/Feature/IssuersTest.php`, `tests/Unit/Issuers/IssuerActivatorTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces:
  - `IdType { Brn='BRN'; Nric='NRIC'; Passport='PASSPORT'; Army='ARMY' }`
  - `IssuerStatus { Draft='draft'; TinVerified='tin_verified'; Authorized='authorized'; Active='active'; Suspended='suspended' }`
  - `LhdnMode { Intermediary='intermediary'; OwnCredentials='own_credentials' }`
  - `Issuer` columns: `tenant_id, name, tin, id_type, id_number, sst_number?, tourism_tax_number?, msic_code, business_activity_description, address_line1, address_line2?, address_line3?, postcode, city, state_code, country_code (default 'MYS'), email, phone, environment, lhdn_mode, einvoice_required (bool, default true), consolidation_enabled (bool, default false), status, activated_at?, tin_verified_at?, authorized_at?`; unique `(tenant_id, tin, environment)`; relation `secret()` (HasOne, Task 7).
  - `Issuer::scopeForCurrentEnvironment()` — filters `environment = TenantContext::environment()`. All issuer routes apply it, so `ek_test_` keys never see production issuers.
  - `IssuerActivator::evaluate(Issuer $issuer): IssuerStatus` and `apply(Issuer $issuer): void` — rules: `authorized` + `hasValidCertificate()` → `active`; `active` without valid certificate → `suspended`; otherwise unchanged. `Issuer::hasValidCertificate(): bool` is provided in Task 7; Task 6 stubs it as `return false;` with a `// replaced in Task 7` note is **not allowed** — instead Task 6 defines it as `return $this->certificate_valid_until !== null && $this->certificate_valid_until->isFuture();` using a nullable `certificate_valid_until` timestamp column on `issuers` (Task 7 fills it on upload).
  - Routes: `POST /v1/issuers`, `GET /v1/issuers`, `GET /v1/issuers/{issuer}`, `PATCH /v1/issuers/{issuer}` — `ability:issuers:manage` for write, `ability:read` for GET.

- [ ] **Step 1: Write failing tests**

`tests/Feature/IssuersTest.php`
```php
<?php

use App\Enums\Environment;
use App\Models\Issuer;
use App\Models\Tenant;

function issuerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Vendor One Sdn Bhd',
        'tin' => 'C12345678901',
        'id_type' => 'BRN',
        'id_number' => '202001012345',
        'sst_number' => null,
        'msic_code' => '47911',
        'business_activity_description' => 'Retail sale via internet',
        'address_line1' => '1 Jalan Test',
        'postcode' => '50000',
        'city' => 'Kuala Lumpur',
        'state_code' => '14',
        'country_code' => 'MYS',
        'email' => 'vendor@example.com',
        'phone' => '+60123456789',
        'lhdn_mode' => 'intermediary',
        'einvoice_required' => true,
        'consolidation_enabled' => true,
    ], $overrides);
}

it('creates an issuer in the credential environment with status draft', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->postJson('/v1/issuers', issuerPayload())
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.environment', 'sandbox')
        ->assertJsonPath('data.tin', 'C12345678901')
        ->assertJsonPath('data.has_certificate', false)
        ->assertJsonPath('data.has_credentials', false);
});

it('validates required fields and enums', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant))
        ->postJson('/v1/issuers', issuerPayload(['id_type' => 'X', 'tin' => '']))
        ->assertStatus(422)
        ->assertJsonFragment(['pointer' => '/id_type'])
        ->assertJsonFragment(['pointer' => '/tin']);
});

it('rejects duplicate tin per tenant and environment but allows across environments', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->postJson('/v1/issuers', issuerPayload())->assertCreated();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->postJson('/v1/issuers', issuerPayload())->assertStatus(409)
        ->assertJsonPath('code', 'issuer_exists');
    $this->withHeaders(serviceHeaders($tenant, 'production'))->postJson('/v1/issuers', issuerPayload())->assertCreated();
});

it('lists only issuers of the current tenant and environment', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    Issuer::factory()->for($a)->create(['environment' => Environment::Sandbox, 'name' => 'A-sandbox']);
    Issuer::factory()->for($a)->create(['environment' => Environment::Production, 'name' => 'A-prod']);
    Issuer::factory()->for($b)->create(['environment' => Environment::Sandbox, 'name' => 'B-sandbox']);

    $this->withHeaders(apiKeyHeaders($a, 'sandbox'))->getJson('/v1/issuers')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'A-sandbox');
});

it('returns 404 for another tenant\'s issuer or another environment\'s issuer', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $prod = Issuer::factory()->for($a)->create(['environment' => Environment::Production]);
    $this->withHeaders(apiKeyHeaders($a, 'sandbox'))->getJson("/v1/issuers/{$prod->id}")->assertStatus(404);
    $this->withHeaders(apiKeyHeaders($b, 'production'))->getJson("/v1/issuers/{$prod->id}")->assertStatus(404);
});

it('updates mutable fields', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['environment' => Environment::Sandbox]);
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->patchJson("/v1/issuers/{$issuer->id}", ['name' => 'Renamed', 'consolidation_enabled' => false])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.consolidation_enabled', false);
});

it('requires issuers:manage for writes', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))
        ->postJson('/v1/issuers', issuerPayload())->assertStatus(403);
});
```

`tests/Unit/Issuers/IssuerActivatorTest.php`
```php
<?php

use App\Enums\IssuerStatus;
use App\Models\Issuer;
use App\Services\Issuers\IssuerActivator;

it('activates an authorized issuer that has a valid certificate', function () {
    $issuer = new Issuer(['status' => IssuerStatus::Authorized, 'certificate_valid_until' => now()->addYear()]);
    expect((new IssuerActivator)->evaluate($issuer))->toBe(IssuerStatus::Active);
});

it('keeps an authorized issuer without certificate as authorized', function () {
    $issuer = new Issuer(['status' => IssuerStatus::Authorized, 'certificate_valid_until' => null]);
    expect((new IssuerActivator)->evaluate($issuer))->toBe(IssuerStatus::Authorized);
});

it('suspends an active issuer whose certificate expired', function () {
    $issuer = new Issuer(['status' => IssuerStatus::Active, 'certificate_valid_until' => now()->subDay()]);
    expect((new IssuerActivator)->evaluate($issuer))->toBe(IssuerStatus::Suspended);
});

it('leaves draft and tin_verified untouched', function () {
    foreach ([IssuerStatus::Draft, IssuerStatus::TinVerified] as $status) {
        $issuer = new Issuer(['status' => $status, 'certificate_valid_until' => now()->addYear()]);
        expect((new IssuerActivator)->evaluate($issuer))->toBe($status);
    }
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/IssuersTest.php tests/Unit/Issuers` → FAIL.

- [ ] **Step 3: Enums**

`app/Enums/IdType.php`
```php
<?php

namespace App\Enums;

enum IdType: string
{
    case Brn = 'BRN';
    case Nric = 'NRIC';
    case Passport = 'PASSPORT';
    case Army = 'ARMY';
}
```
`app/Enums/IssuerStatus.php`
```php
<?php

namespace App\Enums;

enum IssuerStatus: string
{
    case Draft = 'draft';
    case TinVerified = 'tin_verified';
    case Authorized = 'authorized';
    case Active = 'active';
    case Suspended = 'suspended';
}
```
`app/Enums/LhdnMode.php`
```php
<?php

namespace App\Enums;

enum LhdnMode: string
{
    case Intermediary = 'intermediary';
    case OwnCredentials = 'own_credentials';
}
```

- [ ] **Step 4: Migration, model, factory**

`database/migrations/2026_08_19_000004_create_issuers_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tin', 20);
            $table->string('id_type', 10);
            $table->string('id_number', 30);
            $table->string('sst_number', 40)->nullable();
            $table->string('tourism_tax_number', 40)->nullable();
            $table->string('msic_code', 5);
            $table->string('business_activity_description', 300);
            $table->string('address_line1', 150);
            $table->string('address_line2', 150)->nullable();
            $table->string('address_line3', 150)->nullable();
            $table->string('postcode', 10);
            $table->string('city', 50);
            $table->string('state_code', 2);
            $table->string('country_code', 3)->default('MYS');
            $table->string('email', 320);
            $table->string('phone', 20);
            $table->string('environment', 16);
            $table->string('lhdn_mode', 20);
            $table->boolean('einvoice_required')->default(true);
            $table->boolean('consolidation_enabled')->default(false);
            $table->string('status', 20)->default('draft');
            $table->timestamp('tin_verified_at')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('certificate_valid_until')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'tin', 'environment']);
            $table->index(['tenant_id', 'environment', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuers');
    }
};
```

`app/Models/Issuer.php`
```php
<?php

namespace App\Models;

use App\Enums\Environment;
use App\Enums\IdType;
use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Issuer extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'id_type' => IdType::class,
            'environment' => Environment::class,
            'lhdn_mode' => LhdnMode::class,
            'status' => IssuerStatus::class,
            'einvoice_required' => 'boolean',
            'consolidation_enabled' => 'boolean',
            'tin_verified_at' => 'datetime',
            'authorized_at' => 'datetime',
            'activated_at' => 'datetime',
            'certificate_valid_until' => 'datetime',
        ];
    }

    public function secret(): HasOne
    {
        return $this->hasOne(IssuerSecret::class);
    }

    /** @param  Builder<Issuer>  $query */
    public function scopeForCurrentEnvironment(Builder $query): void
    {
        $query->where('environment', app(TenantContext::class)->environment());
    }

    public function hasValidCertificate(): bool
    {
        return $this->certificate_valid_until !== null && $this->certificate_valid_until->isFuture();
    }
}
```
(`IssuerSecret` is created in Task 7; PHPStan will flag the missing class until then — create an empty placeholder model file `app/Models/IssuerSecret.php` **now** containing only `class IssuerSecret extends Model { use HasUlids; protected $guarded = ['id']; }` with the proper namespace and imports; Task 7 fills it in.)

`database/factories/IssuerFactory.php`
```php
<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Enums\IdType;
use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Models\Issuer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Issuer> */
class IssuerFactory extends Factory
{
    protected $model = Issuer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->company(),
            'tin' => 'C'.fake()->unique()->numerify('###########'),
            'id_type' => IdType::Brn,
            'id_number' => fake()->numerify('############'),
            'msic_code' => '47911',
            'business_activity_description' => 'Retail sale via internet',
            'address_line1' => fake()->streetAddress(),
            'postcode' => '50000',
            'city' => 'Kuala Lumpur',
            'state_code' => '14',
            'country_code' => 'MYS',
            'email' => fake()->companyEmail(),
            'phone' => '+60123456789',
            'environment' => Environment::Sandbox,
            'lhdn_mode' => LhdnMode::Intermediary,
            'einvoice_required' => true,
            'consolidation_enabled' => false,
            'status' => IssuerStatus::Draft,
        ];
    }

    public function authorized(): static
    {
        return $this->state(['status' => IssuerStatus::Authorized, 'tin_verified_at' => now(), 'authorized_at' => now()]);
    }

    public function active(): static
    {
        return $this->authorized()->state(['status' => IssuerStatus::Active, 'activated_at' => now(), 'certificate_valid_until' => now()->addYear()]);
    }
}
```

- [ ] **Step 5: IssuerActivator**

`app/Services/Issuers/IssuerActivator.php`
```php
<?php

namespace App\Services\Issuers;

use App\Enums\IssuerStatus;
use App\Models\Issuer;

class IssuerActivator
{
    public function evaluate(Issuer $issuer): IssuerStatus
    {
        return match (true) {
            $issuer->status === IssuerStatus::Authorized && $issuer->hasValidCertificate() => IssuerStatus::Active,
            $issuer->status === IssuerStatus::Active && ! $issuer->hasValidCertificate() => IssuerStatus::Suspended,
            $issuer->status === IssuerStatus::Suspended && $issuer->authorized_at !== null && $issuer->hasValidCertificate() => IssuerStatus::Active,
            default => $issuer->status,
        };
    }

    public function apply(Issuer $issuer): void
    {
        $next = $this->evaluate($issuer);
        if ($next === $issuer->status) {
            return;
        }
        $issuer->status = $next;
        if ($next === IssuerStatus::Active) {
            $issuer->activated_at = now();
        }
        $issuer->save();
    }
}
```

- [ ] **Step 6: Requests, resource, controller, routes**

`app/Http/Requests/StoreIssuerRequest.php`
```php
<?php

namespace App\Http\Requests;

use App\Enums\IdType;
use App\Enums\LhdnMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIssuerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tin' => ['required', 'string', 'regex:/^[A-Z]{1,2}[0-9]{10,12}$/'],
            'id_type' => ['required', Rule::enum(IdType::class)],
            'id_number' => ['required', 'string', 'max:30'],
            'sst_number' => ['nullable', 'string', 'max:40'],
            'tourism_tax_number' => ['nullable', 'string', 'max:40'],
            'msic_code' => ['required', 'digits:5'],
            'business_activity_description' => ['required', 'string', 'max:300'],
            'address_line1' => ['required', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'address_line3' => ['nullable', 'string', 'max:150'],
            'postcode' => ['required', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:50'],
            'state_code' => ['required', 'string', 'size:2'],
            'country_code' => ['sometimes', 'string', 'size:3'],
            'email' => ['required', 'email', 'max:320'],
            'phone' => ['required', 'string', 'max:20'],
            'lhdn_mode' => ['required', Rule::enum(LhdnMode::class)],
            'einvoice_required' => ['sometimes', 'boolean'],
            'consolidation_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Http/Requests/UpdateIssuerRequest.php`
```php
<?php

namespace App\Http\Requests;

use App\Enums\LhdnMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIssuerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'sst_number' => ['nullable', 'string', 'max:40'],
            'tourism_tax_number' => ['nullable', 'string', 'max:40'],
            'msic_code' => ['sometimes', 'digits:5'],
            'business_activity_description' => ['sometimes', 'string', 'max:300'],
            'address_line1' => ['sometimes', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'address_line3' => ['nullable', 'string', 'max:150'],
            'postcode' => ['sometimes', 'string', 'max:10'],
            'city' => ['sometimes', 'string', 'max:50'],
            'state_code' => ['sometimes', 'string', 'size:2'],
            'country_code' => ['sometimes', 'string', 'size:3'],
            'email' => ['sometimes', 'email', 'max:320'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'lhdn_mode' => ['sometimes', Rule::enum(LhdnMode::class)],
            'einvoice_required' => ['sometimes', 'boolean'],
            'consolidation_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
```
(TIN, id_type, id_number and environment are immutable after creation — a new issuer must be created instead.)

`app/Http/Resources/IssuerResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Issuer */
class IssuerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $secret = $this->relationLoaded('secret') ? $this->secret : $this->secret()->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'tin' => $this->tin,
            'id_type' => $this->id_type->value,
            'id_number' => $this->id_number,
            'sst_number' => $this->sst_number,
            'tourism_tax_number' => $this->tourism_tax_number,
            'msic_code' => $this->msic_code,
            'business_activity_description' => $this->business_activity_description,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'line3' => $this->address_line3,
                'postcode' => $this->postcode,
                'city' => $this->city,
                'state_code' => $this->state_code,
                'country_code' => $this->country_code,
            ],
            'email' => $this->email,
            'phone' => $this->phone,
            'environment' => $this->environment->value,
            'lhdn_mode' => $this->lhdn_mode->value,
            'einvoice_required' => $this->einvoice_required,
            'consolidation_enabled' => $this->consolidation_enabled,
            'status' => $this->status->value,
            'has_credentials' => (bool) ($secret?->hasCredentials() ?? false),
            'has_certificate' => (bool) ($secret?->hasCertificate() ?? false),
            'certificate' => $secret?->hasCertificate() ? [
                'subject' => $secret->cert_subject,
                'serial' => $secret->cert_serial,
                'fingerprint' => $secret->cert_fingerprint,
                'not_before' => $secret->cert_not_before?->toIso8601String(),
                'not_after' => $secret->cert_not_after?->toIso8601String(),
            ] : null,
            'tin_verified_at' => $this->tin_verified_at?->toIso8601String(),
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'activated_at' => $this->activated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```
For Task 6 to compile, give the placeholder `IssuerSecret` model these two methods returning `false`: `hasCredentials(): bool`, `hasCertificate(): bool`, and `protected $casts` empty. Task 7 replaces the whole file.

`app/Http/Controllers/Api/V1/IssuerController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIssuerRequest;
use App\Http\Requests\UpdateIssuerRequest;
use App\Http\Resources\IssuerResource;
use App\Models\Issuer;
use App\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IssuerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return IssuerResource::collection(Issuer::forCurrentEnvironment()->with('secret')->latest()->cursorPaginate(50));
    }

    public function store(StoreIssuerRequest $request, TenantContext $context): IssuerResource
    {
        $data = $request->validated();
        $exists = Issuer::forCurrentEnvironment()->where('tin', $data['tin'])->exists();
        if ($exists) {
            throw ProblemException::conflict('An issuer with this TIN already exists in this environment.', 'issuer_exists');
        }
        $issuer = Issuer::create($data + ['environment' => $context->environment()]);

        return new IssuerResource($issuer);
    }

    public function show(Issuer $issuer): IssuerResource
    {
        return new IssuerResource($issuer->load('secret'));
    }

    public function update(UpdateIssuerRequest $request, Issuer $issuer): IssuerResource
    {
        $issuer->update($request->validated());

        return new IssuerResource($issuer->fresh('secret'));
    }
}
```

Route-model binding must also apply the environment scope. Add to `Issuer`:
```php
public function resolveRouteBinding($value, $field = null): ?Model
{
    return static::forCurrentEnvironment()->where($field ?? $this->getRouteKeyName(), $value)->first();
}
```
(import `Illuminate\Database\Eloquent\Model` already present.)

Routes — inside the `tenant` group:
```php
Route::middleware('ability:read')->group(function () {
    Route::get('/issuers', [IssuerController::class, 'index']);
    Route::get('/issuers/{issuer}', [IssuerController::class, 'show']);
});
Route::middleware('ability:issuers:manage')->group(function () {
    Route::post('/issuers', [IssuerController::class, 'store']);
    Route::patch('/issuers/{issuer}', [IssuerController::class, 'update']);
});
```
Note: service tokens have `*` so they pass `ability:read`; API keys must include `read`.

- [ ] **Step 7: Run tests** — `vendor/bin/pest tests/Feature/IssuersTest.php tests/Unit/Issuers` → all pass.

- [ ] **Step 8: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(issuers): issuer model, CRUD API, environment scoping, activator rules"
```

---

### Task 7: Issuer secrets — LHDN credentials and signing certificate upload

**Files:**
- Create: `database/migrations/2026_08_19_000005_create_issuer_secrets_table.php`, `database/migrations/2026_08_19_000006_create_issuer_secret_histories_table.php`, `app/Models/IssuerSecret.php` (replace placeholder), `app/Models/IssuerSecretHistory.php`, `app/Services/Certificates/CertificateInfo.php`, `app/Services/Certificates/CertificateParser.php`, `app/Services/Certificates/InvalidCertificate.php`, `app/Http/Requests/PutIssuerCredentialsRequest.php`, `app/Http/Requests/PutIssuerCertificateRequest.php`, `app/Http/Controllers/Api/V1/IssuerCredentialsController.php`, `app/Http/Controllers/Api/V1/IssuerCertificateController.php`, `tests/Fixtures/certs/test-cert.pem`, `tests/Fixtures/certs/test-key.pem`, `tests/Fixtures/certs/test.p12`, `tests/Fixtures/certs/other-key.pem`, `tests/Unit/Certificates/CertificateParserTest.php`, `tests/Feature/IssuerSecretsTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces:
  - `IssuerSecret` (1:1 issuer): encrypted `lhdn_client_id`, `lhdn_client_secret`, `signing_certificate` (PEM), `signing_key` (PEM, unencrypted private key — we decrypt any passphrase at upload and store the key itself encrypted at rest), plain `cert_subject`, `cert_serial`, `cert_fingerprint` (sha256 hex), `cert_not_before`, `cert_not_after`, `credentials_verified_at`; methods `hasCredentials(): bool`, `hasCertificate(): bool`.
  - `IssuerSecretHistory`: `issuer_id`, `kind` (`certificate`), encrypted `payload` (JSON of the replaced cert+key), `cert_fingerprint`, `replaced_at`.
  - `CertificateParser::fromPem(string $certPem, string $keyPem, ?string $passphrase): CertificateInfo`, `fromPkcs12(string $p12Binary, string $passphrase): CertificateInfo`; throws `InvalidCertificate` (message codes: `certificate_unreadable`, `key_unreadable`, `key_mismatch`, `certificate_expired`).
  - `CertificateInfo { certPem, keyPem (unencrypted), subject, serial, fingerprint, notBefore (CarbonImmutable), notAfter }`.
  - Routes: `PUT /v1/issuers/{issuer}/credentials {client_id, client_secret}` (only when `lhdn_mode = own_credentials`, else 409 `credentials_not_applicable`); `PUT /v1/issuers/{issuer}/certificate` body either `{format:'pem', certificate, private_key, passphrase?}` or `{format:'pkcs12', pkcs12 (base64), passphrase}`. Both `ability:issuers:manage`. On certificate upload: `issuers.certificate_valid_until = not_after`, previous cert archived to history, `IssuerActivator::apply()`.

- [ ] **Step 1: Generate certificate fixtures (once, committed)**

```bash
mkdir -p tests/Fixtures/certs
openssl req -x509 -newkey rsa:2048 -nodes -keyout tests/Fixtures/certs/test-key.pem -out tests/Fixtures/certs/test-cert.pem -days 3650 -subj "/C=MY/O=Billplz Test Issuer/CN=Test Issuer"
openssl pkcs12 -export -inkey tests/Fixtures/certs/test-key.pem -in tests/Fixtures/certs/test-cert.pem -out tests/Fixtures/certs/test.p12 -passout pass:secret -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256
openssl genrsa -out tests/Fixtures/certs/other-key.pem 2048
openssl rsa -in tests/Fixtures/certs/test-key.pem -aes256 -passout pass:keypass -out tests/Fixtures/certs/test-key-encrypted.pem
```
(The `-keypbe/-certpbe AES-256-CBC` flags matter: PHP's OpenSSL 3 rejects the legacy RC2 defaults of OpenSSL 1.1.1.)

- [ ] **Step 2: Write failing tests**

`tests/Unit/Certificates/CertificateParserTest.php`
```php
<?php

use App\Services\Certificates\CertificateParser;
use App\Services\Certificates\InvalidCertificate;

$fx = fn (string $f) => file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

it('parses a PEM certificate and key', function () use ($fx) {
    $info = (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('test-key.pem'), null);
    expect($info->subject)->toContain('CN=Test Issuer')
        ->and($info->fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($info->notAfter->isFuture())->toBeTrue()
        ->and($info->keyPem)->toContain('PRIVATE KEY');
});

it('decrypts a passphrase-protected key and stores it unencrypted in memory', function () use ($fx) {
    $info = (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('test-key-encrypted.pem'), 'keypass');
    expect($info->keyPem)->not->toContain('ENCRYPTED');
});

it('rejects a key that does not match the certificate', function () use ($fx) {
    (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('other-key.pem'), null);
})->throws(InvalidCertificate::class, 'key_mismatch');

it('rejects garbage', function () {
    (new CertificateParser)->fromPem('nope', 'nope', null);
})->throws(InvalidCertificate::class, 'certificate_unreadable');

it('parses a PKCS#12 bundle', function () use ($fx) {
    $info = (new CertificateParser)->fromPkcs12($fx('test.p12'), 'secret');
    expect($info->subject)->toContain('CN=Test Issuer');
});

it('rejects a PKCS#12 bundle with the wrong passphrase', function () use ($fx) {
    (new CertificateParser)->fromPkcs12($fx('test.p12'), 'wrong');
})->throws(InvalidCertificate::class, 'certificate_unreadable');

it('rejects an expired certificate', function () use ($fx) {
    \Illuminate\Support\Carbon::setTestNow('2040-01-01');
    try {
        (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('test-key.pem'), null);
    } finally {
        \Illuminate\Support\Carbon::setTestNow();
    }
})->throws(InvalidCertificate::class, 'certificate_expired');
```

`tests/Feature/IssuerSecretsTest.php`
```php
<?php

use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Models\Issuer;
use App\Models\IssuerSecret;
use App\Models\IssuerSecretHistory;
use App\Models\Tenant;

$fx = fn (string $f) => file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

it('stores own credentials encrypted and never returns them', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['lhdn_mode' => LhdnMode::OwnCredentials]);

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/credentials", ['client_id' => 'cid', 'client_secret' => 'shh'])
        ->assertOk()->assertJsonPath('data.has_credentials', true)->assertJsonMissing(['client_secret' => 'shh']);

    $raw = \DB::table('issuer_secrets')->where('issuer_id', $issuer->id)->first();
    expect($raw->lhdn_client_secret)->not->toBe('shh');
    expect(IssuerSecret::withoutGlobalScopes()->where('issuer_id', $issuer->id)->first()->lhdn_client_secret)->toBe('shh');
});

it('rejects credentials for intermediary-mode issuers', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['lhdn_mode' => LhdnMode::Intermediary]);
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/credentials", ['client_id' => 'cid', 'client_secret' => 'shh'])
        ->assertStatus(409)->assertJsonPath('code', 'credentials_not_applicable');
});

it('uploads a PEM certificate, exposes metadata only, and activates an authorized issuer', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->authorized()->create();

    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", [
            'format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem'),
        ])
        ->assertOk()
        ->assertJsonPath('data.has_certificate', true)
        ->assertJsonPath('data.status', 'active')
        ->assertJsonMissing(['signing_key'])
        ->assertJsonStructure(['data' => ['certificate' => ['subject', 'serial', 'fingerprint', 'not_before', 'not_after']]]);

    expect($issuer->fresh()->certificate_valid_until)->not->toBeNull();
});

it('uploads a PKCS#12 certificate', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pkcs12', 'pkcs12' => base64_encode($fx('test.p12')), 'passphrase' => 'secret'])
        ->assertOk()->assertJsonPath('data.has_certificate', true)->assertJsonPath('data.status', 'draft');
});

it('returns 422 with a code for a mismatched key', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('other-key.pem')])
        ->assertStatus(422)->assertJsonPath('code', 'key_mismatch');
});

it('archives the previous certificate on replacement', function () use ($fx) {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();
    $body = ['format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem')];
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->putJson("/v1/issuers/{$issuer->id}/certificate", $body)->assertOk();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->putJson("/v1/issuers/{$issuer->id}/certificate", $body)->assertOk();
    expect(IssuerSecretHistory::where('issuer_id', $issuer->id)->count())->toBe(1);
});

it('cannot upload to another tenant\'s issuer', function () use ($fx) {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($a)->create();
    $this->withHeaders(serviceHeaders($b, 'sandbox'))
        ->putJson("/v1/issuers/{$issuer->id}/certificate", ['format' => 'pem', 'certificate' => $fx('test-cert.pem'), 'private_key' => $fx('test-key.pem')])
        ->assertStatus(404);
});
```

- [ ] **Step 3: Run to verify failure** — `vendor/bin/pest tests/Unit/Certificates tests/Feature/IssuerSecretsTest.php` → FAIL.

- [ ] **Step 4: Migrations**

`database/migrations/2026_08_19_000005_create_issuer_secrets_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuer_secrets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('lhdn_client_id')->nullable();
            $table->text('lhdn_client_secret')->nullable();
            $table->longText('signing_certificate')->nullable();
            $table->longText('signing_key')->nullable();
            $table->string('cert_subject', 500)->nullable();
            $table->string('cert_serial', 100)->nullable();
            $table->string('cert_fingerprint', 64)->nullable();
            $table->timestamp('cert_not_before')->nullable();
            $table->timestamp('cert_not_after')->nullable();
            $table->timestamp('credentials_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuer_secrets');
    }
};
```

`database/migrations/2026_08_19_000006_create_issuer_secret_histories_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuer_secret_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('issuer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->longText('payload');
            $table->string('cert_fingerprint', 64)->nullable();
            $table->timestamp('replaced_at');
            $table->timestamps();
            $table->index(['issuer_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuer_secret_histories');
    }
};
```

- [ ] **Step 5: Models**

`app/Models/IssuerSecret.php` (replace placeholder entirely)
```php
<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuerSecret extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['lhdn_client_id', 'lhdn_client_secret', 'signing_certificate', 'signing_key'];

    protected function casts(): array
    {
        return [
            'lhdn_client_id' => 'encrypted',
            'lhdn_client_secret' => 'encrypted',
            'signing_certificate' => 'encrypted',
            'signing_key' => 'encrypted',
            'cert_not_before' => 'datetime',
            'cert_not_after' => 'datetime',
            'credentials_verified_at' => 'datetime',
        ];
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(Issuer::class);
    }

    public function hasCredentials(): bool
    {
        return $this->lhdn_client_id !== null && $this->lhdn_client_secret !== null;
    }

    public function hasCertificate(): bool
    {
        return $this->signing_certificate !== null && $this->signing_key !== null;
    }
}
```

`app/Models/IssuerSecretHistory.php`
```php
<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IssuerSecretHistory extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'replaced_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 6: Certificate parser**

`app/Services/Certificates/InvalidCertificate.php`
```php
<?php

namespace App\Services\Certificates;

use RuntimeException;

class InvalidCertificate extends RuntimeException
{
    public static function because(string $code): self
    {
        return new self($code);
    }
}
```

`app/Services/Certificates/CertificateInfo.php`
```php
<?php

namespace App\Services\Certificates;

use Carbon\CarbonImmutable;

final class CertificateInfo
{
    public function __construct(
        public readonly string $certPem,
        public readonly string $keyPem,
        public readonly string $subject,
        public readonly string $serial,
        public readonly string $fingerprint,
        public readonly CarbonImmutable $notBefore,
        public readonly CarbonImmutable $notAfter,
    ) {}
}
```

`app/Services/Certificates/CertificateParser.php`
```php
<?php

namespace App\Services\Certificates;

use Carbon\CarbonImmutable;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

class CertificateParser
{
    public function fromPem(string $certPem, string $keyPem, ?string $passphrase): CertificateInfo
    {
        $cert = @openssl_x509_read($certPem);
        if ($cert === false) {
            throw InvalidCertificate::because('certificate_unreadable');
        }
        $key = @openssl_pkey_get_private($keyPem, $passphrase ?? '');
        if ($key === false) {
            throw InvalidCertificate::because('key_unreadable');
        }

        return $this->build($cert, $key);
    }

    public function fromPkcs12(string $p12Binary, string $passphrase): CertificateInfo
    {
        $bundle = [];
        if (! @openssl_pkcs12_read($p12Binary, $bundle, $passphrase)) {
            throw InvalidCertificate::because('certificate_unreadable');
        }
        $cert = @openssl_x509_read($bundle['cert']);
        $key = @openssl_pkey_get_private($bundle['pkey']);
        if ($cert === false || $key === false) {
            throw InvalidCertificate::because('certificate_unreadable');
        }

        return $this->build($cert, $key);
    }

    private function build(OpenSSLCertificate $cert, OpenSSLAsymmetricKey $key): CertificateInfo
    {
        if (! openssl_x509_check_private_key($cert, $key)) {
            throw InvalidCertificate::because('key_mismatch');
        }
        $parsed = openssl_x509_parse($cert);
        if ($parsed === false) {
            throw InvalidCertificate::because('certificate_unreadable');
        }
        $notBefore = CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t']);
        $notAfter = CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']);
        if ($notAfter->isPast()) {
            throw InvalidCertificate::because('certificate_expired');
        }

        $certPem = '';
        openssl_x509_export($cert, $certPem);
        $keyPem = '';
        openssl_pkey_export($key, $keyPem); // unencrypted PEM; encrypted at rest by the model cast

        $fingerprint = openssl_x509_fingerprint($cert, 'sha256') ?: '';

        return new CertificateInfo(
            certPem: $certPem,
            keyPem: $keyPem,
            subject: $parsed['name'] ?? '',
            serial: (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            fingerprint: strtolower($fingerprint),
            notBefore: $notBefore,
            notAfter: $notAfter,
        );
    }
}
```

- [ ] **Step 7: Requests and controllers**

`app/Http/Requests/PutIssuerCredentialsRequest.php`
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PutIssuerCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:1024'],
        ];
    }
}
```

`app/Http/Requests/PutIssuerCertificateRequest.php`
```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PutIssuerCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'format' => ['required', Rule::in(['pem', 'pkcs12'])],
            'certificate' => ['required_if:format,pem', 'string'],
            'private_key' => ['required_if:format,pem', 'string'],
            'passphrase' => ['nullable', 'string', 'required_if:format,pkcs12'],
            'pkcs12' => ['required_if:format,pkcs12', 'string'],
        ];
    }
}
```

`app/Http/Controllers/Api/V1/IssuerCredentialsController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LhdnMode;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PutIssuerCredentialsRequest;
use App\Http\Resources\IssuerResource;
use App\Models\Issuer;

class IssuerCredentialsController extends Controller
{
    public function update(PutIssuerCredentialsRequest $request, Issuer $issuer): IssuerResource
    {
        if ($issuer->lhdn_mode !== LhdnMode::OwnCredentials) {
            throw ProblemException::conflict('Credentials only apply to issuers in own_credentials mode.', 'credentials_not_applicable');
        }
        $secret = $issuer->secret()->firstOrNew([]);
        $secret->fill([
            'lhdn_client_id' => $request->string('client_id')->toString(),
            'lhdn_client_secret' => $request->string('client_secret')->toString(),
            'credentials_verified_at' => null,
        ]);
        $secret->save();

        return new IssuerResource($issuer->fresh('secret'));
    }
}
```

`app/Http/Controllers/Api/V1/IssuerCertificateController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PutIssuerCertificateRequest;
use App\Http\Resources\IssuerResource;
use App\Models\Issuer;
use App\Models\IssuerSecretHistory;
use App\Services\Certificates\CertificateParser;
use App\Services\Certificates\InvalidCertificate;
use App\Services\Issuers\IssuerActivator;
use Illuminate\Support\Facades\DB;

class IssuerCertificateController extends Controller
{
    public function update(
        PutIssuerCertificateRequest $request,
        Issuer $issuer,
        CertificateParser $parser,
        IssuerActivator $activator,
    ): IssuerResource {
        try {
            $info = $request->string('format')->toString() === 'pem'
                ? $parser->fromPem($request->string('certificate')->toString(), $request->string('private_key')->toString(), $request->input('passphrase'))
                : $parser->fromPkcs12((string) base64_decode($request->string('pkcs12')->toString(), true), (string) $request->input('passphrase'));
        } catch (InvalidCertificate $e) {
            throw new ProblemException(422, 'Unprocessable Entity', 'The certificate could not be accepted.', $e->getMessage());
        }

        DB::transaction(function () use ($issuer, $info): void {
            $secret = $issuer->secret()->firstOrNew([]);
            if ($secret->hasCertificate()) {
                IssuerSecretHistory::create([
                    'issuer_id' => $issuer->id,
                    'kind' => 'certificate',
                    'payload' => ['certificate' => $secret->signing_certificate, 'key' => $secret->signing_key],
                    'cert_fingerprint' => $secret->cert_fingerprint,
                    'replaced_at' => now(),
                ]);
            }
            $secret->fill([
                'signing_certificate' => $info->certPem,
                'signing_key' => $info->keyPem,
                'cert_subject' => $info->subject,
                'cert_serial' => $info->serial,
                'cert_fingerprint' => $info->fingerprint,
                'cert_not_before' => $info->notBefore,
                'cert_not_after' => $info->notAfter,
            ])->save();
            $issuer->forceFill(['certificate_valid_until' => $info->notAfter])->save();
        });

        $activator->apply($issuer);

        return new IssuerResource($issuer->fresh('secret'));
    }
}
```

Routes — inside `ability:issuers:manage` group:
```php
Route::put('/issuers/{issuer}/credentials', [IssuerCredentialsController::class, 'update']);
Route::put('/issuers/{issuer}/certificate', [IssuerCertificateController::class, 'update']);
```

- [ ] **Step 8: Run tests** — `vendor/bin/pest tests/Unit/Certificates tests/Feature/IssuerSecretsTest.php tests/Feature/IssuersTest.php` → all pass.

- [ ] **Step 9: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(issuers): encrypted LHDN credentials and signing certificate upload with history"
```

---

### Task 8: Buyers registry

**Files:**
- Create: `app/Models/Buyer.php`, `database/migrations/2026_08_19_000007_create_buyers_table.php`, `database/factories/BuyerFactory.php`, `app/Http/Requests/StoreBuyerRequest.php`, `app/Http/Requests/UpdateBuyerRequest.php`, `app/Http/Resources/BuyerResource.php`, `app/Http/Controllers/Api/V1/BuyerController.php`, `tests/Feature/BuyersTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Produces: `Buyer` columns `tenant_id, name, tin?, id_type?, id_number?, sst_number?, email?, phone?, address_line1?, address_line2?, address_line3?, postcode?, city?, state_code?, country_code (default 'MYS'), general_public (bool default false), tin_validated_at?, tin_validation_result? (json)`. Routes `POST /v1/buyers`, `GET /v1/buyers`, `GET /v1/buyers/{buyer}`, `PATCH /v1/buyers/{buyer}` — write `ability:documents:write`, read `ability:read`. Buyers are **not** environment-scoped (same buyer usable from sandbox and production).

- [ ] **Step 1: Write failing tests `tests/Feature/BuyersTest.php`**

```php
<?php

use App\Models\Buyer;
use App\Models\Tenant;

it('creates and lists buyers per tenant', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/buyers', ['name' => 'Ali Bin Abu', 'tin' => 'IG12345678901', 'id_type' => 'NRIC', 'id_number' => '900101011234', 'email' => 'ali@example.com'])
        ->assertCreated()->assertJsonPath('data.name', 'Ali Bin Abu')->assertJsonPath('data.general_public', false);

    Buyer::factory()->for(Tenant::factory())->create();
    $this->withHeaders(apiKeyHeaders($tenant))->getJson('/v1/buyers')->assertOk()->assertJsonCount(1, 'data');
});

it('accepts a general public buyer without tin', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/buyers', ['name' => 'General Public', 'general_public' => true])
        ->assertCreated()->assertJsonPath('data.general_public', true);
});

it('requires id_type together with id_number', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))
        ->postJson('/v1/buyers', ['name' => 'X', 'id_number' => '123'])
        ->assertStatus(422)->assertJsonFragment(['pointer' => '/id_type']);
});

it('updates and scopes by tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $buyer = Buyer::factory()->for($a)->create();
    $this->withHeaders(apiKeyHeaders($a))->patchJson("/v1/buyers/{$buyer->id}", ['name' => 'New'])->assertOk()->assertJsonPath('data.name', 'New');
    $this->withHeaders(apiKeyHeaders($b))->getJson("/v1/buyers/{$buyer->id}")->assertStatus(404);
});

it('requires documents:write for writes', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant, 'sandbox', ['read']))->postJson('/v1/buyers', ['name' => 'X'])->assertStatus(403);
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/BuyersTest.php` → FAIL.

- [ ] **Step 3: Migration, model, factory**

`database/migrations/2026_08_19_000007_create_buyers_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tin', 20)->nullable();
            $table->string('id_type', 10)->nullable();
            $table->string('id_number', 30)->nullable();
            $table->string('sst_number', 40)->nullable();
            $table->string('email', 320)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('address_line1', 150)->nullable();
            $table->string('address_line2', 150)->nullable();
            $table->string('address_line3', 150)->nullable();
            $table->string('postcode', 10)->nullable();
            $table->string('city', 50)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('country_code', 3)->default('MYS');
            $table->boolean('general_public')->default(false);
            $table->timestamp('tin_validated_at')->nullable();
            $table->json('tin_validation_result')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'tin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyers');
    }
};
```

`app/Models/Buyer.php`
```php
<?php

namespace App\Models;

use App\Enums\IdType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Buyer extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'id_type' => IdType::class,
            'general_public' => 'boolean',
            'tin_validated_at' => 'datetime',
            'tin_validation_result' => 'array',
        ];
    }
}
```

`database/factories/BuyerFactory.php`
```php
<?php

namespace Database\Factories;

use App\Enums\IdType;
use App\Models\Buyer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Buyer> */
class BuyerFactory extends Factory
{
    protected $model = Buyer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'tin' => 'IG'.fake()->numerify('###########'),
            'id_type' => IdType::Nric,
            'id_number' => fake()->numerify('############'),
            'email' => fake()->safeEmail(),
            'country_code' => 'MYS',
            'general_public' => false,
        ];
    }
}
```

- [ ] **Step 4: Requests, resource, controller, routes**

`app/Http/Requests/StoreBuyerRequest.php`
```php
<?php

namespace App\Http\Requests;

use App\Enums\IdType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuyerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:300'],
            'tin' => ['nullable', 'string', 'max:20'],
            'id_type' => ['nullable', 'required_with:id_number', Rule::enum(IdType::class)],
            'id_number' => ['nullable', 'required_with:id_type', 'string', 'max:30'],
            'sst_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:320'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'address_line3' => ['nullable', 'string', 'max:150'],
            'postcode' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:50'],
            'state_code' => ['nullable', 'string', 'size:2'],
            'country_code' => ['sometimes', 'string', 'size:3'],
            'general_public' => ['sometimes', 'boolean'],
        ];
    }
}
```

`app/Http/Requests/UpdateBuyerRequest.php` — same as `StoreBuyerRequest` but `'name' => ['sometimes', 'string', 'max:300']` (copy the file, change the class name and that one rule).

`app/Http/Resources/BuyerResource.php`
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Buyer */
class BuyerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tin' => $this->tin,
            'id_type' => $this->id_type?->value,
            'id_number' => $this->id_number,
            'sst_number' => $this->sst_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => [
                'line1' => $this->address_line1,
                'line2' => $this->address_line2,
                'line3' => $this->address_line3,
                'postcode' => $this->postcode,
                'city' => $this->city,
                'state_code' => $this->state_code,
                'country_code' => $this->country_code,
            ],
            'general_public' => $this->general_public,
            'tin_validated_at' => $this->tin_validated_at?->toIso8601String(),
            'tin_validation_result' => $this->tin_validation_result,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

`app/Http/Controllers/Api/V1/BuyerController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBuyerRequest;
use App\Http\Requests\UpdateBuyerRequest;
use App\Http\Resources\BuyerResource;
use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BuyerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Buyer::query()->latest();
        if ($tin = $request->query('tin')) {
            $query->where('tin', $tin);
        }

        return BuyerResource::collection($query->cursorPaginate(50));
    }

    public function store(StoreBuyerRequest $request): BuyerResource
    {
        return new BuyerResource(Buyer::create($request->validated()));
    }

    public function show(Buyer $buyer): BuyerResource
    {
        return new BuyerResource($buyer);
    }

    public function update(UpdateBuyerRequest $request, Buyer $buyer): BuyerResource
    {
        $buyer->update($request->validated());

        return new BuyerResource($buyer->fresh());
    }
}
```

Routes — inside `tenant` group:
```php
Route::middleware('ability:read')->group(function () {
    Route::get('/buyers', [BuyerController::class, 'index']);
    Route::get('/buyers/{buyer}', [BuyerController::class, 'show']);
});
Route::middleware('ability:documents:write')->group(function () {
    Route::post('/buyers', [BuyerController::class, 'store']);
    Route::patch('/buyers/{buyer}', [BuyerController::class, 'update']);
});
```

- [ ] **Step 5: Run tests** — `vendor/bin/pest tests/Feature/BuyersTest.php` → all pass.

- [ ] **Step 6: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(buyers): tenant buyer registry API"
```

---

### Task 9: Reference data (codes table, importer, API)

**Files:**
- Create: `app/Models/ReferenceCode.php`, `database/migrations/2026_08_19_000008_create_reference_codes_table.php`, `database/reference/{document_types,tax_types,state_codes,payment_modes,classification_codes,unit_types,currencies,country_codes,msic_codes}.json`, `app/Console/Commands/RefreshReferenceData.php`, `database/seeders/ReferenceDataSeeder.php`, `app/Http/Controllers/Api/V1/ReferenceController.php`, `tests/Feature/ReferenceDataTest.php`
- Modify: `routes/api.php`, `database/seeders/DatabaseSeeder.php`, spec §7.1 (one table instead of nine — see Step 7)

**Interfaces:**
- Produces:
  - `reference_codes` table: `set` (string), `code`, `description`, `extra` (json nullable), `version` (string), unique `(set, code)`. Not tenant-owned.
  - `ReferenceCode::SETS = ['document_types','tax_types','state_codes','payment_modes','classification_codes','unit_types','currencies','country_codes','msic_codes']`.
  - JSON file format: `{"version": "2026-08", "items": [{"code": "01", "description": "Invoice", "extra": {...}?}]}`.
  - `php artisan einvoice:refresh-reference-data {--path=database/reference}` upserts every `<set>.json` in the directory.
  - `GET /v1/reference/{set}` → `{data: [{code, description, extra}], meta: {version, count}}`, `ETag` = sha1 of set+version, `304` on `If-None-Match`, cached 1h. Unknown set → 404. Requires `auth.api` (any credential, no tenant needed).

- [ ] **Step 1: Write failing tests `tests/Feature/ReferenceDataTest.php`**

```php
<?php

use App\Models\ReferenceCode;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;

it('imports reference JSON files idempotently', function () {
    Artisan::call('einvoice:refresh-reference-data');
    $first = ReferenceCode::count();
    expect($first)->toBeGreaterThan(50);
    expect(ReferenceCode::where('set', 'tax_types')->where('code', '01')->value('description'))->toBe('Sales Tax');
    expect(ReferenceCode::where('set', 'document_types')->where('code', '11')->value('description'))->toBe('Self-billed Invoice');

    Artisan::call('einvoice:refresh-reference-data');
    expect(ReferenceCode::count())->toBe($first);
});

it('serves a reference set with an ETag and 304', function () {
    Artisan::call('einvoice:refresh-reference-data');
    $tenant = Tenant::factory()->create();
    $res = $this->withHeaders(apiKeyHeaders($tenant))->getJson('/v1/reference/state_codes')->assertOk()
        ->assertJsonPath('data.0.code', '00')->assertJsonStructure(['meta' => ['version', 'count']]);
    $etag = $res->headers->get('ETag');
    expect($etag)->not->toBeNull();
    $this->withHeaders(apiKeyHeaders($tenant) + ['If-None-Match' => $etag])->getJson('/v1/reference/state_codes')->assertStatus(304);
});

it('works for service tokens without a tenant header', function () {
    Artisan::call('einvoice:refresh-reference-data');
    $this->withHeader('Authorization', 'Bearer '.serviceToken())->getJson('/v1/reference/tax_types')->assertOk();
});

it('returns 404 for unknown sets', function () {
    $tenant = Tenant::factory()->create();
    $this->withHeaders(apiKeyHeaders($tenant))->getJson('/v1/reference/nope')->assertStatus(404);
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/ReferenceDataTest.php` → FAIL.

- [ ] **Step 3: Migration and model**

`database/migrations/2026_08_19_000008_create_reference_codes_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_codes', function (Blueprint $table) {
            $table->id();
            $table->string('set', 40);
            $table->string('code', 20);
            $table->string('description', 500);
            $table->json('extra')->nullable();
            $table->string('version', 20);
            $table->timestamps();
            $table->unique(['set', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_codes');
    }
};
```

`app/Models/ReferenceCode.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceCode extends Model
{
    public const SETS = [
        'document_types', 'tax_types', 'state_codes', 'payment_modes', 'classification_codes',
        'unit_types', 'currencies', 'country_codes', 'msic_codes',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['extra' => 'array'];
    }
}
```

- [ ] **Step 4: Reference JSON files** — create `database/reference/` with these files. The first five sets are the complete LHDN lists; `unit_types`, `currencies`, `country_codes`, `msic_codes` ship with a starter subset — replace them with the full lists downloaded from the LHDN SDK code tables (https://sdk.myinvois.hasil.gov.my/codes/) converted to the same `{version, items}` shape before production go-live (data step, tracked in Plan 3).

`document_types.json`
```json
{"version":"2026-08","items":[
 {"code":"01","description":"Invoice"},{"code":"02","description":"Credit Note"},{"code":"03","description":"Debit Note"},{"code":"04","description":"Refund Note"},
 {"code":"11","description":"Self-billed Invoice"},{"code":"12","description":"Self-billed Credit Note"},{"code":"13","description":"Self-billed Debit Note"},{"code":"14","description":"Self-billed Refund Note"}
]}
```
`tax_types.json`
```json
{"version":"2026-08","items":[
 {"code":"01","description":"Sales Tax"},{"code":"02","description":"Service Tax"},{"code":"03","description":"Tourism Tax"},{"code":"04","description":"High-Value Goods Tax"},
 {"code":"05","description":"Sales Tax on Low Value Goods"},{"code":"06","description":"Not Applicable"},{"code":"E","description":"Tax exemption (where applicable)"}
]}
```
`state_codes.json`
```json
{"version":"2026-08","items":[
 {"code":"00","description":"All States"},{"code":"01","description":"Johor"},{"code":"02","description":"Kedah"},{"code":"03","description":"Kelantan"},{"code":"04","description":"Melaka"},
 {"code":"05","description":"Negeri Sembilan"},{"code":"06","description":"Pahang"},{"code":"07","description":"Pulau Pinang"},{"code":"08","description":"Perak"},{"code":"09","description":"Perlis"},
 {"code":"10","description":"Selangor"},{"code":"11","description":"Terengganu"},{"code":"12","description":"Sabah"},{"code":"13","description":"Sarawak"},{"code":"14","description":"Wilayah Persekutuan Kuala Lumpur"},
 {"code":"15","description":"Wilayah Persekutuan Labuan"},{"code":"16","description":"Wilayah Persekutuan Putrajaya"},{"code":"17","description":"Not Applicable"}
]}
```
`payment_modes.json`
```json
{"version":"2026-08","items":[
 {"code":"01","description":"Cash"},{"code":"02","description":"Cheque"},{"code":"03","description":"Bank Transfer"},{"code":"04","description":"Credit Card"},
 {"code":"05","description":"Debit Card"},{"code":"06","description":"e-Wallet / Digital Wallet"},{"code":"07","description":"Digital Bank"},{"code":"08","description":"Others"}
]}
```
`classification_codes.json`
```json
{"version":"2026-08","items":[
 {"code":"001","description":"Breastfeeding equipment"},{"code":"002","description":"Child care centres and kindergartens fees"},{"code":"003","description":"Computer, smartphone or tablet"},
 {"code":"004","description":"Consolidated e-Invoice"},{"code":"005","description":"Construction materials"},{"code":"006","description":"Disbursement"},{"code":"007","description":"Donation"},
 {"code":"008","description":"e-Commerce - e-Invoice to buyer / purchaser"},{"code":"009","description":"e-Commerce - Self-billed e-Invoice to seller, logistics, etc."},
 {"code":"010","description":"Education fees"},{"code":"011","description":"Goods on consignment (Consignor)"},{"code":"012","description":"Goods on consignment (Consignee)"},
 {"code":"013","description":"Gym membership"},{"code":"014","description":"Insurance - Education and medical benefits"},{"code":"015","description":"Insurance - Takaful or life insurance"},
 {"code":"016","description":"Interest and financing expenses"},{"code":"017","description":"Internet subscription"},{"code":"018","description":"Land and building"},
 {"code":"019","description":"Medical examination for learning disabilities"},{"code":"020","description":"Medical examination or vaccination expenses"},{"code":"021","description":"Medical expenses for serious diseases"},
 {"code":"022","description":"Others"},{"code":"023","description":"Petroleum operations (as defined in Petroleum (Income Tax) Act 1967)"},{"code":"024","description":"Private retirement scheme or deferred annuity scheme"},
 {"code":"025","description":"Motor vehicle"},{"code":"026","description":"Subscription of books / journals / magazines / newspapers / other similar publications"},{"code":"027","description":"Reimbursement"},
 {"code":"028","description":"Rental of motor vehicle"},{"code":"029","description":"EV charging facilities (Installation, rental, sale / purchase or subscription fees)"},{"code":"030","description":"Repair and maintenance"},
 {"code":"031","description":"Research and development"},{"code":"032","description":"Foreign income"},{"code":"033","description":"Self-billed - Betting and gaming"},
 {"code":"034","description":"Self-billed - Importation of goods"},{"code":"035","description":"Self-billed - Importation of services"},{"code":"036","description":"Self-billed - Others"},
 {"code":"037","description":"Self-billed - Monetary payment to agents, dealers or distributors"},{"code":"038","description":"Sports equipment, rental / entry fees for sports facilities, registration in sports competition or sports training fees imposed by associations / sports clubs / companies registered with the Sports Commissioner or Companies Commission of Malaysia and carrying out sports activities as listed under the Sports Development Act 1997"},
 {"code":"039","description":"Supporting equipment for disabled person"},{"code":"040","description":"Voluntary contribution to approved provident fund"},{"code":"041","description":"Dental examination or treatment"},
 {"code":"042","description":"Fertility treatment"},{"code":"043","description":"Treatment and home care nursing, daycare centres and residential care centers"},{"code":"044","description":"Vouchers, gift cards, loyalty points, etc"},
 {"code":"045","description":"Self-billed - Non-monetary payment to agents, dealers or distributors"}
]}
```
`unit_types.json` (starter)
```json
{"version":"2026-08-starter","items":[
 {"code":"C62","description":"one (unit)"},{"code":"EA","description":"each"},{"code":"KGM","description":"kilogram"},{"code":"GRM","description":"gram"},{"code":"MTR","description":"metre"},
 {"code":"LTR","description":"litre"},{"code":"MLT","description":"millilitre"},{"code":"HUR","description":"hour"},{"code":"DAY","description":"day"},{"code":"WEE","description":"week"},
 {"code":"MON","description":"month"},{"code":"ANN","description":"year"},{"code":"XPK","description":"package"},{"code":"XBX","description":"box"},{"code":"SET","description":"set"}
]}
```
`currencies.json` (starter)
```json
{"version":"2026-08-starter","items":[
 {"code":"MYR","description":"Malaysian Ringgit"},{"code":"USD","description":"US Dollar"},{"code":"SGD","description":"Singapore Dollar"},{"code":"EUR","description":"Euro"},
 {"code":"GBP","description":"Pound Sterling"},{"code":"AUD","description":"Australian Dollar"},{"code":"JPY","description":"Yen"},{"code":"CNY","description":"Yuan Renminbi"},
 {"code":"IDR","description":"Rupiah"},{"code":"THB","description":"Baht"},{"code":"HKD","description":"Hong Kong Dollar"},{"code":"INR","description":"Indian Rupee"}
]}
```
`country_codes.json` (starter)
```json
{"version":"2026-08-starter","items":[
 {"code":"MYS","description":"MALAYSIA"},{"code":"SGP","description":"SINGAPORE"},{"code":"IDN","description":"INDONESIA"},{"code":"THA","description":"THAILAND"},{"code":"BRN","description":"BRUNEI DARUSSALAM"},
 {"code":"PHL","description":"PHILIPPINES"},{"code":"VNM","description":"VIET NAM"},{"code":"CHN","description":"CHINA"},{"code":"HKG","description":"HONG KONG"},{"code":"JPN","description":"JAPAN"},
 {"code":"KOR","description":"KOREA, REPUBLIC OF"},{"code":"IND","description":"INDIA"},{"code":"AUS","description":"AUSTRALIA"},{"code":"GBR","description":"UNITED KINGDOM"},{"code":"USA","description":"UNITED STATES"}
]}
```
`msic_codes.json` (starter)
```json
{"version":"2026-08-starter","items":[
 {"code":"00000","description":"NOT APPLICABLE"},{"code":"47911","description":"Retail sale via internet"},{"code":"47912","description":"Retail sale via mail order houses"},
 {"code":"62010","description":"Computer programming activities"},{"code":"62020","description":"Computer consultancy and computer facilities management activities"},{"code":"63110","description":"Data processing, hosting and related activities"},
 {"code":"64191","description":"Commercial banks"},{"code":"66190","description":"Other activities auxiliary to financial service activities n.e.c."},{"code":"73100","description":"Advertising"},
 {"code":"46900","description":"Non-specialized wholesale trade"},{"code":"56101","description":"Restaurants and restaurant cum night clubs"},{"code":"85499","description":"Other education n.e.c."}
]}
```

- [ ] **Step 5: Importer command and seeder**

`app/Console/Commands/RefreshReferenceData.php`
```php
<?php

namespace App\Console\Commands;

use App\Models\ReferenceCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RefreshReferenceData extends Command
{
    protected $signature = 'einvoice:refresh-reference-data {--path= : Directory containing <set>.json files (default database/reference)}';

    protected $description = 'Import/upsert LHDN reference code lists from JSON files.';

    public function handle(): int
    {
        $dir = $this->option('path') ?: database_path('reference');
        $total = 0;
        foreach (ReferenceCode::SETS as $set) {
            $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR."{$set}.json";
            if (! is_file($file)) {
                $this->warn("skip {$set}: {$file} not found");

                continue;
            }
            /** @var array{version:string, items: array<int, array{code:string, description:string, extra?:array<string,mixed>}>} $json */
            $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $rows = array_map(fn (array $item) => [
                'set' => $set,
                'code' => $item['code'],
                'description' => $item['description'],
                'extra' => isset($item['extra']) ? json_encode($item['extra']) : null,
                'version' => $json['version'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $json['items']);
            foreach (array_chunk($rows, 500) as $chunk) {
                ReferenceCode::upsert($chunk, ['set', 'code'], ['description', 'extra', 'version', 'updated_at']);
            }
            Cache::forget("reference:{$set}");
            $total += count($rows);
            $this->info(sprintf('%-22s %5d rows (v%s)', $set, count($rows), $json['version']));
        }
        $this->info("Done: {$total} rows.");

        return self::SUCCESS;
    }
}
```

`database/seeders/ReferenceDataSeeder.php`
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('einvoice:refresh-reference-data');
    }
}
```
In `DatabaseSeeder::run()` replace the body with `$this->call(ReferenceDataSeeder::class);`.

- [ ] **Step 6: Controller and route**

`app/Http/Controllers/Api/V1/ReferenceController.php`
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\ReferenceCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ReferenceController extends Controller
{
    public function show(Request $request, string $set): JsonResponse|Response
    {
        if (! in_array($set, ReferenceCode::SETS, true)) {
            throw ProblemException::notFound("Unknown reference set '{$set}'.");
        }

        /** @var array{items: array<int, array<string,mixed>>, version: string} $payload */
        $payload = Cache::remember("reference:{$set}", 3600, function () use ($set): array {
            $rows = ReferenceCode::where('set', $set)->orderBy('code')->get(['code', 'description', 'extra', 'version']);

            return [
                'items' => $rows->map(fn (ReferenceCode $r) => ['code' => $r->code, 'description' => $r->description, 'extra' => $r->extra])->all(),
                'version' => (string) ($rows->first()?->version ?? ''),
            ];
        });

        $etag = '"'.sha1($set.'|'.$payload['version'].'|'.count($payload['items'])).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304)->setEtag($etag, false);
        }

        return response()
            ->json(['data' => $payload['items'], 'meta' => ['version' => $payload['version'], 'count' => count($payload['items'])]])
            ->setEtag($etag, false)
            ->setPublic()
            ->setMaxAge(3600);
    }
}
```
Route — inside `auth.api` group but **outside** the `tenant` group:
```php
Route::get('/reference/{set}', [ReferenceController::class, 'show']);
```
(`response()->noContent(304)` sets the status; `setEtag($etag, false)` keeps our quoted value.)

- [ ] **Step 7: Amend spec §7.1** — replace the "Tables: `ref_msic_codes`, … `ref_document_types`" sentence with: "Table: `reference_codes` (`set`, `code`, `description`, `extra` json, `version`; unique `(set, code)`) holding all nine LHDN code lists."

- [ ] **Step 8: Run tests** — `vendor/bin/pest tests/Feature/ReferenceDataTest.php` → all pass.

- [ ] **Step 9: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(reference): LHDN reference code lists, importer command, cached API with ETag"
```

---

### Task 10: Audit log

**Files:**
- Create: `app/Models/AuditLog.php`, `database/migrations/2026_08_19_000009_create_audit_logs_table.php`, `app/Services/Audit/AuditLogger.php`, `tests/Feature/AuditLogTest.php`
- Modify: `app/Http/Controllers/Api/V1/ApiKeyController.php`, `IssuerController.php`, `IssuerCredentialsController.php`, `IssuerCertificateController.php`, `TenantController.php`

**Interfaces:**
- Produces:
  - `audit_logs`: `tenant_id` nullable (not tenant-scoped via trait — global table with an explicit `tenant_id`), `actor_type`, `actor_id`, `actor_name`, `action` (e.g. `api_key.created`), `subject_type`, `subject_id`, `changes` json nullable, `ip`, `request_id`, `created_at`.
  - `AuditLogger::record(string $action, ?Model $subject = null, ?array $changes = null): AuditLog` — reads actor + tenant from `TenantContext`, ip + `X-Request-Id` (or generated) from the current request.
  - Actions recorded in Plan 1: `tenant.created`, `api_key.created`, `api_key.revoked`, `issuer.created`, `issuer.updated` (changes = dirty attrs), `issuer.credentials_updated` (no values), `issuer.certificate_updated` (changes = `{fingerprint}`).

- [ ] **Step 1: Write failing tests `tests/Feature/AuditLogTest.php`**

```php
<?php

use App\Models\AuditLog;
use App\Models\Issuer;
use App\Models\Tenant;

it('records api key creation and revocation with actor and tenant', function () {
    $tenant = Tenant::factory()->create();
    $id = $this->withHeaders(serviceHeaders($tenant) + ['X-Request-Id' => 'req-1'])
        ->postJson('/v1/api-keys', ['name' => 'k', 'environment' => 'sandbox', 'abilities' => ['read']])
        ->json('data.id');
    $this->withHeaders(serviceHeaders($tenant))->deleteJson("/v1/api-keys/{$id}")->assertNoContent();

    $logs = AuditLog::where('tenant_id', $tenant->id)->orderBy('created_at')->get();
    expect($logs->pluck('action')->all())->toBe(['api_key.created', 'api_key.revoked'])
        ->and($logs[0]->actor_type)->toBe('service')
        ->and($logs[0]->subject_id)->toBe($id)
        ->and($logs[0]->request_id)->toBe('req-1');
});

it('records issuer updates with a diff but never secret values', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create(['name' => 'Old', 'lhdn_mode' => 'own_credentials']);
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->patchJson("/v1/issuers/{$issuer->id}", ['name' => 'New'])->assertOk();
    $this->withHeaders(serviceHeaders($tenant, 'sandbox'))->putJson("/v1/issuers/{$issuer->id}/credentials", ['client_id' => 'cid', 'client_secret' => 'topsecret'])->assertOk();

    $update = AuditLog::where('action', 'issuer.updated')->first();
    expect($update->changes)->toBe(['name' => ['from' => 'Old', 'to' => 'New']]);
    $cred = AuditLog::where('action', 'issuer.credentials_updated')->first();
    expect(json_encode($cred->toArray()))->not->toContain('topsecret');
});
```

- [ ] **Step 2: Run to verify failure** — `vendor/bin/pest tests/Feature/AuditLogTest.php` → FAIL.

- [ ] **Step 3: Migration, model, logger**

`database/migrations/2026_08_19_000009_create_audit_logs_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->nullable()->index();
            $table->string('actor_type', 20)->nullable();
            $table->string('actor_id', 26)->nullable();
            $table->string('actor_name', 100)->nullable();
            $table->string('action', 60)->index();
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 26)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
```

`app/Models/AuditLog.php`
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }
}
```

`app/Services/Audit/AuditLogger.php`
```php
<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    public function __construct(private readonly TenantContext $context, private readonly Request $request) {}

    /** @param array<string, mixed>|null $changes */
    public function record(string $action, ?Model $subject = null, ?array $changes = null): AuditLog
    {
        $actor = $this->context->actor();

        return AuditLog::create([
            'tenant_id' => $this->context->tenantOrNull()?->getKey(),
            'actor_type' => $actor?->type,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'changes' => $changes,
            'ip' => $this->request->ip(),
            'request_id' => $this->request->header('X-Request-Id') ?? (string) Str::ulid(),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    public static function diff(Model $model): array
    {
        $out = [];
        foreach ($model->getChanges() as $key => $to) {
            if (in_array($key, ['updated_at'], true)) {
                continue;
            }
            $out[$key] = ['from' => $model->getOriginal($key), 'to' => $to];
        }

        return $out;
    }
}
```

- [ ] **Step 4: Wire into controllers** (inject `AuditLogger $audit` via method injection):

- `TenantController::store` → after create: `$audit->record('tenant.created', $tenant);`
- `ApiKeyController::store` → `$audit->record('api_key.created', $key, ['name' => $key->name, 'environment' => $key->environment->value, 'abilities' => $key->abilities]);`
- `ApiKeyController::destroy` → `$audit->record('api_key.revoked', $apiKey);`
- `IssuerController::store` → `$audit->record('issuer.created', $issuer);`
- `IssuerController::update` → after `$issuer->update(...)`: `$audit->record('issuer.updated', $issuer, AuditLogger::diff($issuer));` (call `diff` **before** `fresh()`; note `getChanges()` is populated after `update()`; the enum-cast values are stored via `getOriginal` as raw strings — cast values in the diff to `->value` when they are `BackedEnum` instances: add `if ($to instanceof \BackedEnum) $to = $to->value;` and same for `from` inside `diff()`).
- `IssuerCredentialsController::update` → `$audit->record('issuer.credentials_updated', $issuer);`
- `IssuerCertificateController::update` → `$audit->record('issuer.certificate_updated', $issuer, ['fingerprint' => $info->fingerprint]);`

- [ ] **Step 5: Run the full suite** — `php artisan test` → all pass.

- [ ] **Step 6: Lint/analyse and commit**

```bash
composer check
git add -A && git commit -m "feat(audit): audit log for tenants, keys, issuers and secrets"
```

---

### Task 11: Tenant-isolation sweep, CI workflow, README

**Files:**
- Create: `tests/Feature/TenantIsolationSweepTest.php`, `.github/workflows/ci.yml`, `README.md` (replace Laravel default)

**Interfaces:**
- Produces: a table-driven test that guarantees every tenant-scoped route returns 404 (or an empty list) for another tenant; CI running `composer check` on push/PR.

- [ ] **Step 1: Write the sweep test `tests/Feature/TenantIsolationSweepTest.php`**

```php
<?php

use App\Enums\Environment;
use App\Models\ApiKey;
use App\Models\Buyer;
use App\Models\Issuer;
use App\Models\Tenant;

/**
 * Every tenant-owned resource route must 404 for a different tenant.
 * When you add a tenant-scoped resource route in a later plan, add a row here.
 */
dataset('cross_tenant_routes', function () {
    return [
        'issuer show' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'GET', '/v1/issuers/{id}'],
        'issuer update' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'PATCH', '/v1/issuers/{id}'],
        'issuer credentials' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox, 'lhdn_mode' => 'own_credentials']), 'PUT', '/v1/issuers/{id}/credentials'],
        'issuer certificate' => [fn (Tenant $t) => Issuer::factory()->for($t)->create(['environment' => Environment::Sandbox]), 'PUT', '/v1/issuers/{id}/certificate'],
        'buyer show' => [fn (Tenant $t) => Buyer::factory()->for($t)->create(), 'GET', '/v1/buyers/{id}'],
        'buyer update' => [fn (Tenant $t) => Buyer::factory()->for($t)->create(), 'PATCH', '/v1/buyers/{id}'],
        'api key revoke' => [fn (Tenant $t) => ApiKey::generate($t, 'k', Environment::Sandbox, ['read'])['key'], 'DELETE', '/v1/api-keys/{id}'],
    ];
});

it('returns 404 for cross-tenant access', function (Closure $make, string $method, string $path) {
    $owner = Tenant::factory()->create();
    $intruder = Tenant::factory()->create();
    $resource = $make($owner);
    $url = str_replace('{id}', $resource->getKey(), $path);

    $this->withHeaders(serviceHeaders($intruder, 'sandbox'))
        ->json($method, $url, ['name' => 'x', 'client_id' => 'a', 'client_secret' => 'b', 'format' => 'pem', 'certificate' => 'x', 'private_key' => 'y'])
        ->assertStatus(404);
})->with('cross_tenant_routes');

it('lists are empty for another tenant', function () {
    $owner = Tenant::factory()->create();
    $intruder = Tenant::factory()->create();
    Issuer::factory()->for($owner)->create(['environment' => Environment::Sandbox]);
    Buyer::factory()->for($owner)->create();
    ApiKey::generate($owner, 'k', Environment::Sandbox, ['read']);

    foreach (['/v1/issuers', '/v1/buyers', '/v1/api-keys'] as $path) {
        $this->withHeaders(serviceHeaders($intruder, 'sandbox'))->getJson($path)->assertOk()->assertJsonCount(0, 'data');
    }
});
```

- [ ] **Step 2: Run** — `vendor/bin/pest tests/Feature/TenantIsolationSweepTest.php` → all pass (route-model binding + `TenantScope` already guarantee this; the test locks it in).

- [ ] **Step 3: CI workflow `.github/workflows/ci.yml`**

```yaml
name: CI
on:
  push:
    branches: [main]
  pull_request:
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, intl, openssl, pdo_sqlite, redis
          coverage: none
      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress
      - name: Prepare app
        run: cp .env.example .env && php artisan key:generate
      - name: Lint
        run: vendor/bin/pint --test
      - name: Static analysis
        run: vendor/bin/phpstan analyse --memory-limit=1G
      - name: Tests
        run: php artisan test
```

- [ ] **Step 4: README.md** (replace the Laravel default)

```markdown
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

## Auth model

| Credential | Header | Tenant | Environment |
|---|---|---|---|
| Service token `sk_<service>_…` | `Authorization: Bearer`, `X-Tenant-Id`, `X-Environment` | from header | from header (default production) |
| API key `ek_test_…` / `ek_live_…` | `Authorization: Bearer` | bound to key | bound to key |

All errors are `application/problem+json`. Cross-tenant resources are always `404`.
```

- [ ] **Step 5: Full check and commit**

```bash
composer check
git add -A && git commit -m "test: tenant isolation sweep; ci: GitHub Actions; docs: README"
```

---

## Plan self-review (done at authoring time)

- **Spec coverage (Plan 1 scope):** §3.1 tenant model/trait/scope → Task 2; §3.2 credentials/abilities/environment rule → Tasks 4–5; §3.3 per-credential throttle → Laravel default `throttle:api` on the api group (60/min) — per-issuer LHDN budget deferred to Plan 3; §4.1 issuer → Task 6; §4.2 secrets/history/cert metadata → Task 7; §4.3 buyers → Task 8; §4.4 TIN validation → Plan 3 (needs LHDN client); §7.1 reference data → Task 9 (one table, spec amended); §7.5 audit → Task 10; §8 tenants/api-keys/issuers/credentials/certificate/buyers/reference/health routes → Tasks 4–9; `verify-tin`/`authorize` → Plan 3; §9 error shape → Task 3; §10 unit/feature/isolation/static → every task + Task 11; §11 stack → Task 1.
- **Placeholders:** none; the only "starter" content is reference *data* (unit types, currencies, countries, MSIC) explicitly flagged for replacement with the full LHDN SDK lists before go-live.
- **Type consistency:** `TenantContext::bind(?Tenant, ?Actor, Environment)` used identically in Tasks 2, 4, 5; `ApiKey::generate(Tenant, string, Environment, array)` used in Tasks 5, 11 and helpers; `IssuerSecret::hasCredentials()/hasCertificate()` used by `IssuerResource` (Task 6) and defined in Task 7 (placeholder in Task 6 carries the same signatures); `IssuerActivator::apply()` used in Task 7; `AuditLogger::record()/diff()` used in Task 10 only.
