<?php

namespace App\Auth;

use App\Enums\UserRole;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class InviteUser
{
    public function __construct(
        private readonly SendLoginLink $sendLoginLink,
        private readonly TenantContext $context,
        private readonly AuditLogger $logger,
    ) {}

    public function handle(Tenant $tenant, string $email, string $name, UserRole $role, ?Issuer $issuer): User
    {
        $this->assertVendorIssuerPairing($role, $issuer);

        $normalized = SendLoginLink::normalize($email);

        if (User::withoutGlobalScopes()->where('email', $normalized)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
        }

        $user = new User;
        $user->tenant_id = $tenant->id;
        $user->name = $name;
        $user->email = $normalized;
        $user->role = $role;
        $user->issuer_id = $issuer?->id;
        $user->invited_at = now();
        $user->save();

        $this->auditInvited($tenant, $user);

        // An invite is a user row plus a magic link (spec §4.3); re-invite
        // just calls this again, so the send path only lives in one place.
        $this->sendLoginLink->handle($user->email);

        return $user;
    }

    private function assertVendorIssuerPairing(UserRole $role, ?Issuer $issuer): void
    {
        if ($role === UserRole::Vendor && $issuer === null) {
            throw ValidationException::withMessages(['issuer' => 'A vendor invite requires an issuer.']);
        }

        if ($role !== UserRole::Vendor && $issuer !== null) {
            throw ValidationException::withMessages(['issuer' => 'Only vendor invites may specify an issuer.']);
        }
    }

    private function auditInvited(Tenant $tenant, User $user): void
    {
        // If a caller (e.g. the Team/Vendors pages, Task 7) already has the
        // right tenant context bound, keep its real actor rather than
        // overwriting it with a synthetic one.
        if ($this->context->has() && $this->context->tenant()->is($tenant)) {
            $this->logger->record('user.invited', $user);

            return;
        }

        $previousTenant = $this->context->tenantOrNull();
        $previousActor = $this->context->actor();
        $previousEnvironment = $this->context->environment();

        $this->context->bind($tenant, new Actor('system', 'console', 'einvoice:invite-user', ['*']), $previousEnvironment);
        try {
            $this->logger->record('user.invited', $user);
        } finally {
            if ($previousTenant !== null) {
                $this->context->bind($previousTenant, $previousActor, $previousEnvironment);
            } else {
                $this->context->clear();
            }
        }
    }
}
