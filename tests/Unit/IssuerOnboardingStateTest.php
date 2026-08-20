<?php

use App\Domain\Onboarding\IssuerOnboardingState;
use App\Enums\Environment;
use App\Enums\IssuerStatus;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Builds one environment-locked issuer row from a compact spec so each
 * dataset case only states what's relevant to it. Keys, all optional:
 *  - profile: bool, default true — false blanks the required profile fields
 *  - tin: bool, default false — sets tin_verified_at
 *  - mode: null|'credentials'|'authorized'|'authorized_credentials'
 *      null                  = no secret, not authorized
 *      'credentials'         = own-credentials client id/secret stored, not yet authorized
 *      'authorized'          = authorized_at set (intermediary consent recorded), no credentials
 *      'authorized_credentials' = both — own credentials that have since been authorized
 *  - certificate: bool, default false — secret carries certificate metadata
 *  - status: IssuerStatus, default Draft
 *  - tin_number: string — pins the tin, so a sandbox/production pair can be
 *    made to represent "the same business" (siblingFor() matches on tin)
 *
 * @param  array{profile?: bool, tin?: bool, mode?: ?string, certificate?: bool, status?: IssuerStatus, tin_number?: string}  $spec
 */
function onboardingIssuer(Tenant $tenant, Environment $env, array $spec = []): Issuer
{
    $overrides = ['environment' => $env, 'status' => $spec['status'] ?? IssuerStatus::Draft];
    if (isset($spec['tin_number'])) {
        $overrides['tin'] = $spec['tin_number'];
    }
    if (($spec['profile'] ?? true) === false) {
        $overrides['name'] = '';
    }
    if ($spec['tin'] ?? false) {
        $overrides['tin_verified_at'] = now();
    }
    $mode = $spec['mode'] ?? null;
    if (in_array($mode, ['authorized', 'authorized_credentials'], true)) {
        $overrides['authorized_at'] = now();
    }
    $issuer = Issuer::factory()->for($tenant)->create($overrides);

    $secretFields = [];
    if (in_array($mode, ['credentials', 'authorized_credentials'], true)) {
        $secretFields += ['lhdn_client_id' => 'client-id', 'lhdn_client_secret' => 'client-secret'];
    }
    if ($spec['certificate'] ?? false) {
        $secretFields += [
            'signing_certificate' => 'cert-pem', 'signing_key' => 'key-pem',
            'cert_subject' => 'CN=Test', 'cert_serial' => '123', 'cert_fingerprint' => 'ff',
        ];
    }
    if ($secretFields !== []) {
        $issuer->secret()->create($secretFields);
    }

    return $issuer;
}

function passedSandboxTest(Issuer $sandboxIssuer): void
{
    Document::factory()->for($sandboxIssuer)->valid()->create([
        'environment' => Environment::Sandbox,
        'source_system' => 'dashboard-test',
    ]);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
});

it('always returns the six steps in a fixed order', function () {
    $issuer = onboardingIssuer($this->tenant, Environment::Sandbox);
    $keys = array_map(fn ($s) => $s->key, IssuerOnboardingState::for($issuer, Environment::Sandbox)->steps());
    expect($keys)->toBe(['profile', 'tin', 'mode', 'certificate', 'sandbox_test', 'go_live']);
});

it('derives step completion, the current step, and go-live blockers from issuer state', function (
    Closure $build,
    Environment $view,
    array $expected,
    string $expectedCurrent,
) {
    /** @var Issuer $issuer */
    $issuer = $build($this->tenant);

    $state = IssuerOnboardingState::for($issuer, $view);
    $byKey = [];
    foreach ($state->steps() as $step) {
        $byKey[$step->key] = $step;
    }

    foreach (['profile', 'tin', 'mode', 'certificate', 'sandbox_test', 'go_live'] as $key) {
        expect($byKey[$key]->done)->toBe($expected[$key])->and($byKey[$key]->key)->toBe($key);
    }
    expect($byKey['go_live']->blocked_reason)->toBe($expected['go_live_blocked'] ?? null);
    foreach (['profile', 'tin', 'mode', 'certificate', 'sandbox_test'] as $key) {
        expect($byKey[$key]->blocked_reason)->toBeNull();
    }
    expect($state->current())->toBe($expectedCurrent);
})->with([
    'profile incomplete blocks everything' => [
        fn (Tenant $t) => onboardingIssuer($t, Environment::Sandbox, ['profile' => false]),
        Environment::Sandbox,
        ['profile' => false, 'tin' => false, 'mode' => false, 'certificate' => false, 'sandbox_test' => false, 'go_live' => false, 'go_live_blocked' => 'profile_incomplete'],
        'profile',
    ],
    'profile done, tin not verified' => [
        fn (Tenant $t) => onboardingIssuer($t, Environment::Sandbox, ['profile' => true]),
        Environment::Sandbox,
        ['profile' => true, 'tin' => false, 'mode' => false, 'certificate' => false, 'sandbox_test' => false, 'go_live' => false, 'go_live_blocked' => 'tin_not_verified'],
        'tin',
    ],
    'tin verified, mode not chosen yet' => [
        fn (Tenant $t) => onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true]),
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => false, 'certificate' => false, 'sandbox_test' => false, 'go_live' => false, 'go_live_blocked' => 'mode_incomplete'],
        'mode',
    ],
    'own credentials stored but not yet authorized counts as mode done' => [
        fn (Tenant $t) => onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'credentials']),
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => true, 'certificate' => false, 'sandbox_test' => false, 'go_live' => false, 'go_live_blocked' => 'certificate_missing'],
        'certificate',
    ],
    'intermediary consent (authorized_at) counts as mode done' => [
        fn (Tenant $t) => onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized']),
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => true, 'certificate' => false, 'sandbox_test' => false, 'go_live' => false, 'go_live_blocked' => 'certificate_missing'],
        'certificate',
    ],
    'certificate stored, sandbox test not yet run' => [
        fn (Tenant $t) => onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]),
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => true, 'certificate' => true, 'sandbox_test' => false, 'go_live' => false, 'go_live_blocked' => 'sandbox_test_pending'],
        'sandbox_test',
    ],
    'sandbox test passed, no production issuer created yet' => [
        function (Tenant $t) {
            $issuer = onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]);
            passedSandboxTest($issuer);

            return $issuer;
        },
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => true, 'certificate' => true, 'sandbox_test' => true, 'go_live' => false, 'go_live_blocked' => 'production_mode_incomplete'],
        'go_live',
    ],
    'production issuer exists but its certificate is missing' => [
        function (Tenant $t) {
            $issuer = onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]);
            passedSandboxTest($issuer);
            onboardingIssuer($t, Environment::Production, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => false, 'status' => IssuerStatus::Authorized, 'tin_number' => $issuer->tin]);

            return $issuer;
        },
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => true, 'certificate' => true, 'sandbox_test' => true, 'go_live' => false, 'go_live_blocked' => 'production_certificate_missing'],
        'go_live',
    ],
    'production mode and certificate ready but issuer not yet active' => [
        function (Tenant $t) {
            $issuer = onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]);
            passedSandboxTest($issuer);
            onboardingIssuer($t, Environment::Production, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true, 'status' => IssuerStatus::Authorized, 'tin_number' => $issuer->tin]);

            return $issuer;
        },
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => true, 'certificate' => true, 'sandbox_test' => true, 'go_live' => false, 'go_live_blocked' => null],
        'go_live',
    ],
    'go-live done once the production issuer is active' => [
        function (Tenant $t) {
            $issuer = onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]);
            passedSandboxTest($issuer);
            onboardingIssuer($t, Environment::Production, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true, 'status' => IssuerStatus::Active, 'tin_number' => $issuer->tin]);

            return $issuer;
        },
        Environment::Sandbox,
        ['profile' => true, 'tin' => true, 'mode' => true, 'certificate' => true, 'sandbox_test' => true, 'go_live' => true, 'go_live_blocked' => null],
        'go_live',
    ],
    'viewing production reflects the production row, not the sandbox one, while sandbox_test still checks sandbox' => [
        function (Tenant $t) {
            $sandbox = onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]);
            passedSandboxTest($sandbox);
            onboardingIssuer($t, Environment::Production, ['profile' => false, 'tin_number' => $sandbox->tin]);

            return $sandbox;
        },
        Environment::Production,
        ['profile' => false, 'tin' => false, 'mode' => false, 'certificate' => false, 'sandbox_test' => true, 'go_live' => false, 'go_live_blocked' => 'production_mode_incomplete'],
        'profile',
    ],
    'viewing production before any production row exists' => [
        function (Tenant $t) {
            $sandbox = onboardingIssuer($t, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]);
            passedSandboxTest($sandbox);

            return $sandbox;
        },
        Environment::Production,
        ['profile' => false, 'tin' => false, 'mode' => false, 'certificate' => false, 'sandbox_test' => true, 'go_live' => false, 'go_live_blocked' => 'production_mode_incomplete'],
        'profile',
    ],
]);

it('never leaks another tenant\'s issuer into the same-tin sibling lookup', function () {
    $issuer = onboardingIssuer($this->tenant, Environment::Sandbox, ['profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true]);
    passedSandboxTest($issuer);

    $otherTenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($otherTenant, null, Environment::Production);
    onboardingIssuer($otherTenant, Environment::Production, [
        'profile' => true, 'tin' => true, 'mode' => 'authorized', 'certificate' => true, 'status' => IssuerStatus::Active,
    ])->forceFill(['tin' => $issuer->tin])->save();

    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $state = IssuerOnboardingState::for($issuer, Environment::Sandbox);
    $goLive = collect($state->steps())->firstWhere('key', 'go_live');

    expect($goLive->done)->toBeFalse()->and($goLive->blocked_reason)->toBe('production_mode_incomplete');
});
