<?php

use App\Auth\InviteUser;
use App\Auth\SendLoginLink;
use App\Enums\UserRole;
use App\Mail\LoginLinkMail;
use App\Models\AuditLog;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

it('creates an owner user and audits the invite', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create();

    $user = app(InviteUser::class)->handle($tenant, 'Owner@Example.com', 'Jane Owner', UserRole::Owner, null);

    expect($user->exists)->toBeTrue();
    expect($user->email)->toBe('owner@example.com');
    expect($user->tenant_id)->toBe($tenant->id);
    expect($user->role)->toBe(UserRole::Owner);
    expect($user->invited_at)->not->toBeNull();
    expect($user->isActive())->toBeFalse();

    $invited = AuditLog::query()->where('action', 'user.invited')->where('subject_id', $user->id)->first();
    expect($invited)->not->toBeNull();
    expect($invited->tenant_id)->toBe($tenant->id);

    Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail) => $mail->hasTo($user->email));
});

it('rejects a vendor invite without an issuer', function () {
    $tenant = Tenant::factory()->create();

    expect(fn () => app(InviteUser::class)->handle($tenant, 'vendor@example.com', 'Vendor', UserRole::Vendor, null))
        ->toThrow(ValidationException::class);
});

it('rejects a non-vendor invite that specifies an issuer', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();

    expect(fn () => app(InviteUser::class)->handle($tenant, 'member@example.com', 'Member', UserRole::Member, $issuer))
        ->toThrow(ValidationException::class);
});

it('creates a vendor user pinned to its issuer', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();

    $user = app(InviteUser::class)->handle($tenant, 'vendor@example.com', 'Vendor Co', UserRole::Vendor, $issuer);

    expect($user->role)->toBe(UserRole::Vendor);
    expect($user->issuer_id)->toBe($issuer->id);
});

it('rejects an invite to an email already registered anywhere', function () {
    Mail::fake();
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    User::factory()->for($tenantA)->create(['email' => 'taken@example.com', 'role' => UserRole::Owner]);

    expect(fn () => app(InviteUser::class)->handle($tenantB, 'taken@example.com', 'Someone', UserRole::Owner, null))
        ->toThrow(ValidationException::class);
});

it('re-invite resends a login link without creating a duplicate user', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    app(SendLoginLink::class)->handle($user->email);

    Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail) => $mail->hasTo('owner@example.com'));
    expect(User::withoutGlobalScopes()->where('email', 'owner@example.com')->count())->toBe(1);
});

it('creates and mails a user via the einvoice:invite-user command, resolving the tenant by id', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('einvoice:invite-user', [
        'tenant' => $tenant->id,
        'email' => 'owner@example.com',
        '--name' => 'Jane Owner',
        '--role' => 'owner',
    ]);

    expect($exitCode)->toBe(0);
    $user = User::withoutGlobalScopes()->where('email', 'owner@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->tenant_id)->toBe($tenant->id);
    Mail::assertSent(LoginLinkMail::class, fn (LoginLinkMail $mail) => $mail->hasTo('owner@example.com'));
});

it('resolves the tenant by a unique name via the command', function () {
    Mail::fake();
    $tenant = Tenant::factory()->create(['name' => 'Acme Merchant']);

    $exitCode = Artisan::call('einvoice:invite-user', [
        'tenant' => 'Acme Merchant',
        'email' => 'owner@example.com',
        '--role' => 'owner',
    ]);

    expect($exitCode)->toBe(0);
    expect(User::withoutGlobalScopes()->where('email', 'owner@example.com')->exists())->toBeTrue();
});

it('fails the command when the tenant name is ambiguous', function () {
    Tenant::factory()->create(['name' => 'Acme Merchant']);
    Tenant::factory()->create(['name' => 'Acme Merchant']);

    $exitCode = Artisan::call('einvoice:invite-user', [
        'tenant' => 'Acme Merchant',
        'email' => 'owner@example.com',
        '--role' => 'owner',
    ]);

    expect($exitCode)->toBe(1);
    expect(User::withoutGlobalScopes()->where('email', 'owner@example.com')->exists())->toBeFalse();
});

it('fails the command when the tenant cannot be resolved', function () {
    $exitCode = Artisan::call('einvoice:invite-user', [
        'tenant' => 'does-not-exist',
        'email' => 'owner@example.com',
        '--role' => 'owner',
    ]);

    expect($exitCode)->toBe(1);
});

it('fails the command for a vendor role without --issuer', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('einvoice:invite-user', [
        'tenant' => $tenant->id,
        'email' => 'vendor@example.com',
        '--role' => 'vendor',
    ]);

    expect($exitCode)->toBe(1);
    expect(User::withoutGlobalScopes()->where('email', 'vendor@example.com')->exists())->toBeFalse();
});

it('fails the command for an invalid role', function () {
    $tenant = Tenant::factory()->create();

    $exitCode = Artisan::call('einvoice:invite-user', [
        'tenant' => $tenant->id,
        'email' => 'owner@example.com',
        '--role' => 'superadmin',
    ]);

    expect($exitCode)->toBe(1);
});
