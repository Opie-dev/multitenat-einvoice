<?php

namespace App\Auth;

use App\Models\LoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;

class ConsumeLoginToken
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $logger,
    ) {}

    public function handle(string $plaintext): ?User
    {
        $hash = hash('sha256', $plaintext);

        /** @var LoginToken|null $token */
        $token = LoginToken::query()->where('token_hash', $hash)->first();
        if ($token === null || $token->consumed_at !== null || $token->expires_at->isPast()) {
            return null;
        }

        /** @var User|null $user */
        $user = User::withoutGlobalScopes()->find($token->user_id);
        if ($user === null) {
            return null;
        }

        $token->forceFill(['consumed_at' => now()])->save();

        // Invalidate every other outstanding (unconsumed) token for this user.
        LoginToken::query()
            ->where('user_id', $user->id)
            ->whereKeyNot($token->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit($user);

        return $user;
    }

    private function audit(User $user): void
    {
        $tenant = $user->tenant;
        if (! $tenant instanceof Tenant) {
            return;
        }

        if ($this->context->has() && $this->context->tenant()->is($tenant)) {
            $this->logger->record('user.logged_in', $user);

            return;
        }

        $previousTenant = $this->context->tenantOrNull();
        $previousActor = $this->context->actor();
        $previousEnvironment = $this->context->environment();

        $this->context->bind($tenant, new Actor('user', $user->id, $user->name, []), $previousEnvironment);
        try {
            $this->logger->record('user.logged_in', $user);
        } finally {
            if ($previousTenant !== null) {
                $this->context->bind($previousTenant, $previousActor, $previousEnvironment);
            } else {
                $this->context->clear();
            }
        }
    }
}
