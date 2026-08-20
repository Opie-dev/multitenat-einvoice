<?php

namespace App\Auth;

use App\Mail\LoginLinkMail;
use App\Models\LoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Enumeration-safe by construction: the caller (App\Http\Controllers\Web\AuthController)
 * always renders the same page regardless of whether handle() found a user, and
 * this class never throws or returns a value that would let the caller branch on
 * "did the email exist" (spec 2026-08-20-onboarding-dashboard-design.md §4.2).
 */
class SendLoginLink
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $logger,
    ) {}

    public function handle(string $email): void
    {
        $normalized = self::normalize($email);

        /** @var User|null $user */
        $user = User::withoutGlobalScopes()->where('email', $normalized)->first();
        if ($user === null) {
            return;
        }

        $plaintext = Str::random(64);

        LoginToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => now()->addMinutes(15),
        ]);

        Mail::to($user->email)->send(new LoginLinkMail($user, $plaintext));

        $this->audit($user);
    }

    /** Lookups and stored emails both go through this so matching is case/whitespace-insensitive everywhere. */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function audit(User $user): void
    {
        $tenant = $user->tenant;
        if (! $tenant instanceof Tenant) {
            return;
        }

        if ($this->context->has() && $this->context->tenant()->is($tenant)) {
            $this->logger->record('user.login_link_sent', $user);

            return;
        }

        $previousTenant = $this->context->tenantOrNull();
        $previousActor = $this->context->actor();
        $previousEnvironment = $this->context->environment();

        $this->context->bind($tenant, new Actor('user', $user->id, $user->name, []), $previousEnvironment);
        try {
            $this->logger->record('user.login_link_sent', $user);
        } finally {
            if ($previousTenant !== null) {
                $this->context->bind($previousTenant, $previousActor, $previousEnvironment);
            } else {
                $this->context->clear();
            }
        }
    }
}
