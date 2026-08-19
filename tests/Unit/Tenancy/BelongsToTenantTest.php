<?php

use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\Exceptions\NoTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
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
    expect(TenantScopedWidget::withoutGlobalScope(TenantScope::class)->count())->toBe(1);
});
