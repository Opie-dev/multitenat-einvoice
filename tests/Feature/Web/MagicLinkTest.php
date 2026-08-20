<?php

use App\Enums\UserRole;
use App\Mail\LoginLinkMail;
use App\Models\AuditLog;
use App\Models\Issuer;
use App\Models\LoginToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the login page', function () {
    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('returns a byte-identical response for an existing and an unknown email', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    $known = $this->post('/login/link', ['email' => 'owner@example.com']);
    $unknown = $this->post('/login/link', ['email' => 'nobody@example.com']);

    $known->assertOk();
    $unknown->assertOk();

    $knownPage = $known->viewData('page');
    $unknownPage = $unknown->viewData('page');

    expect($knownPage['component'])->toBe('Auth/LinkSent');
    expect($unknownPage['component'])->toBe('Auth/LinkSent');
    expect($knownPage['component'])->toBe($unknownPage['component']);
    // toEqual (deep equality), not toBe (strict identity): each response
    // carries its own empty stdClass for `errors`, so `===` would fail on
    // object identity even though the props are byte-for-byte the same JSON.
    expect($knownPage['props'])->toEqual($unknownPage['props']);
    expect(json_encode($knownPage['props']))->toBe(json_encode($unknownPage['props']));
    expect($known->getStatusCode())->toBe($unknown->getStatusCode());
});

it('sends mail only when the email matches an existing user', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    $this->post('/login/link', ['email' => 'owner@example.com'])->assertOk();
    $this->post('/login/link', ['email' => 'nobody@example.com'])->assertOk();

    Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $mail) use ($user) {
        return $mail->hasTo($user->email) && strlen($mail->token) === 64;
    });
    Mail::assertSentCount(1);
});

it('audits the login link send without storing the token plaintext', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    $this->post('/login/link', ['email' => 'owner@example.com'])->assertOk();

    $log = AuditLog::query()->where('action', 'user.login_link_sent')->where('subject_id', $user->id)->firstOrFail();
    expect($log->tenant_id)->toBe($tenant->id);
    expect(json_encode($log->changes))->not->toContain(DB::table('login_tokens')->value('token_hash'));

    $sentToken = null;
    Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $mail) use (&$sentToken) {
        $sentToken = $mail->token;

        return true;
    });

    expect(json_encode($log->toArray()))->not->toContain((string) $sentToken);
});

it('stores only the sha-256 hash of the token, never the plaintext', function () {
    Mail::fake();

    $tenant = Tenant::factory()->create();
    User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    $this->post('/login/link', ['email' => 'owner@example.com'])->assertOk();

    $sentToken = null;
    Mail::assertSent(LoginLinkMail::class, function (LoginLinkMail $mail) use (&$sentToken) {
        $sentToken = $mail->token;

        return true;
    });

    expect($sentToken)->not->toBeNull();
    expect(DB::table('login_tokens')->where('token_hash', $sentToken)->exists())->toBeFalse();
    $this->assertDatabaseHas('login_tokens', ['token_hash' => hash('sha256', (string) $sentToken)]);
});

it('logs in on a valid token and sets last_login_at', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);
    $plaintext = Str::random(64);
    LoginToken::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plaintext),
        'expires_at' => now()->addMinutes(15),
    ]);

    $response = $this->get('/login/'.$plaintext);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user->fresh());
    expect($user->fresh()->last_login_at)->not->toBeNull();

    $log = AuditLog::query()->where('action', 'user.logged_in')->where('subject_id', $user->id)->first();
    expect($log)->not->toBeNull();
});

it('regenerates the session id on successful login', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);
    $plaintext = Str::random(64);
    LoginToken::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plaintext),
        'expires_at' => now()->addMinutes(15),
    ]);

    $cookieName = config('session.cookie');

    $first = $this->get('/login');
    $beforeCookie = $first->getCookie($cookieName);
    expect($beforeCookie)->not->toBeNull();
    $beforeId = $beforeCookie->getValue();

    $second = $this->withUnencryptedCookie($cookieName, $beforeId)->get('/login/'.$plaintext);
    $second->assertRedirect('/dashboard');

    $afterCookie = $second->getCookie($cookieName);
    expect($afterCookie)->not->toBeNull();
    expect($afterCookie->getValue())->not->toBe($beforeId);
});

it('invalidates the users other outstanding tokens on successful consumption', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    $consumed = Str::random(64);
    $sibling = Str::random(64);
    LoginToken::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $consumed), 'expires_at' => now()->addMinutes(15)]);
    $siblingToken = LoginToken::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $sibling), 'expires_at' => now()->addMinutes(15)]);

    $this->get('/login/'.$consumed)->assertRedirect('/dashboard');

    expect($siblingToken->fresh()->consumed_at)->not->toBeNull();
});

it('rejects an expired token without logging in', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);
    $plaintext = Str::random(64);
    LoginToken::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plaintext),
        'expires_at' => now()->subMinute(),
    ]);

    $response = $this->get('/login/'.$plaintext);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Auth/LinkInvalid'));
    $this->assertGuest();
});

it('rejects an already-consumed token without logging in', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);
    $plaintext = Str::random(64);
    LoginToken::query()->create([
        'user_id' => $user->id,
        'token_hash' => hash('sha256', $plaintext),
        'expires_at' => now()->addMinutes(15),
        'consumed_at' => now(),
    ]);

    $response = $this->get('/login/'.$plaintext);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Auth/LinkInvalid'));
    $this->assertGuest();
});

it('rejects a tampered token without logging in', function () {
    $response = $this->get('/login/'.Str::random(64));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Auth/LinkInvalid'));
    $this->assertGuest();
});

it('throttles the 6th login-link request from the same source within a minute', function () {
    $tenant = Tenant::factory()->create();
    User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    for ($i = 0; $i < 5; $i++) {
        $this->post('/login/link', ['email' => 'owner@example.com'])->assertOk();
    }

    $this->post('/login/link', ['email' => 'owner@example.com'])->assertStatus(429);
});

it('logs out and invalidates the session', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['email' => 'owner@example.com', 'role' => UserRole::Owner]);

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});

it('enforces the vendor-issuer CHECK constraint at the database level', function () {
    $tenant = Tenant::factory()->create();

    expect(fn () => DB::table('users')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $tenant->id,
        'name' => 'Bad Vendor',
        'email' => 'bad-vendor@example.com',
        'role' => 'vendor',
        'issuer_id' => null,
        'invited_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows a vendor row when paired with an issuer', function () {
    $tenant = Tenant::factory()->create();
    $issuer = Issuer::factory()->for($tenant)->create();

    $user = User::factory()->for($tenant)->vendor($issuer)->create();

    expect($user->fresh()->issuer_id)->toBe($issuer->id);
    expect($user->isVendor())->toBeTrue();
});
