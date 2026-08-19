<?php

use App\Enums\Environment;
use App\Models\Tenant;
use App\Tenancy\Exceptions\NoTenantContext;
use App\Tenancy\Jobs\TenantAwareJob;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

class RecordingTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAwareJob;

    /** @var array<int, array{tenant: ?string, env: string, actor: ?string}> */
    public static array $seen = [];

    public function __construct()
    {
        $this->captureTenantContext();
    }

    public function handle(TenantContext $context): void
    {
        self::$seen[] = [
            'tenant' => $context->tenantOrNull()?->id,
            'env' => $context->environment()->value,
            'actor' => $context->actor()?->label(),
        ];
    }
}

beforeEach(fn () => RecordingTenantJob::$seen = []);

it('captures tenant and environment at construction and rebinds them when handled', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->bind($tenant, null, Environment::Sandbox);

    $job = new RecordingTenantJob;
    expect($job->tenantId)->toBe($tenant->id)->and($job->tenantEnvironment)->toBe('sandbox');

    $context->clear();
    dispatch_sync($job);

    expect(RecordingTenantJob::$seen)->toHaveCount(1)
        ->and(RecordingTenantJob::$seen[0]['tenant'])->toBe($tenant->id)
        ->and(RecordingTenantJob::$seen[0]['env'])->toBe('sandbox')
        ->and(RecordingTenantJob::$seen[0]['actor'])->toBe('system:RecordingTenantJob');
});

it('clears the context after the job finishes', function () {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->bind($tenant, null, Environment::Production);
    $job = new RecordingTenantJob;
    $context->clear();

    dispatch_sync($job);

    expect($context->has())->toBeFalse();
});

it('restores the caller\'s own tenant context after an inline (sync) job finishes', function () {
    $tenantA = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->bind($tenantA, null, Environment::Sandbox);

    // Job is constructed (and thus captures its tenant) while A is still bound.
    $job = new RecordingTenantJob;

    dispatch_sync($job);

    expect(RecordingTenantJob::$seen)->toHaveCount(1)
        ->and(RecordingTenantJob::$seen[0]['tenant'])->toBe($tenantA->id)
        ->and($context->has())->toBeTrue()
        ->and($context->tenant()->id)->toBe($tenantA->id)
        ->and($context->environment())->toBe(Environment::Sandbox);
});

it('throws when constructed without a tenant context', function () {
    app(TenantContext::class)->clear();
    new RecordingTenantJob;
})->throws(NoTenantContext::class);

it('serialises only scalar tenant data (queue-safe)', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($tenant, null, Environment::Sandbox);
    Bus::fake();
    RecordingTenantJob::dispatch();
    Bus::assertDispatched(RecordingTenantJob::class, fn (RecordingTenantJob $j) => $j->tenantId === $tenant->id && $j->tenantEnvironment === 'sandbox');
});
