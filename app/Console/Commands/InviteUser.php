<?php

namespace App\Console\Commands;

use App\Auth\InviteUser as InviteUserAction;
use App\Enums\UserRole;
use App\Models\Issuer;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class InviteUser extends Command
{
    protected $signature = 'einvoice:invite-user
        {tenant : Tenant id or (unique) name}
        {email : Email address to invite}
        {--name= : Display name (defaults to the email address)}
        {--role=owner : owner, member, or vendor}
        {--issuer= : Issuer id (required, vendor role only)}';

    protected $description = 'Invite a dashboard user and mail them a magic sign-in link. Also usable for support/recovery.';

    public function handle(InviteUserAction $inviteUser): int
    {
        $tenant = $this->resolveTenant((string) $this->argument('tenant'));
        if ($tenant === null) {
            return self::FAILURE;
        }

        $roleValue = (string) $this->option('role');
        $role = UserRole::tryFrom($roleValue);
        if ($role === null) {
            $this->error("Invalid role \"{$roleValue}\". Expected one of: owner, member, vendor.");

            return self::FAILURE;
        }

        $issuer = null;
        $issuerId = $this->option('issuer');
        if ($issuerId !== null && $issuerId !== '') {
            $issuer = Issuer::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($issuerId);
            if ($issuer === null) {
                $this->error('Issuer not found for this tenant.');

                return self::FAILURE;
            }
        }

        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');
        if ($name === '') {
            $name = $email;
        }

        try {
            $user = $inviteUser->handle($tenant, $email, $name, $role, $issuer);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info("Invited {$user->email} as {$role->value}. A sign-in link has been sent.");

        return self::SUCCESS;
    }

    private function resolveTenant(string $identifier): ?Tenant
    {
        $byId = Tenant::query()->find($identifier);
        if ($byId !== null) {
            return $byId;
        }

        $matches = Tenant::query()->where('name', $identifier)->get();
        if ($matches->count() === 1) {
            /** @var Tenant $only */
            $only = $matches->first();

            return $only;
        }

        if ($matches->count() > 1) {
            $this->error("Multiple tenants named \"{$identifier}\"; use the tenant id instead.");

            return null;
        }

        $this->error("Tenant \"{$identifier}\" not found.");

        return null;
    }
}
