<?php

namespace App\Auth;

use App\Models\ApiKey;
use App\Models\ServiceToken;

class CredentialResolver
{
    public function resolve(string $bearer): ?ResolvedCredential
    {
        if (str_starts_with($bearer, 'sk_')) {
            return $this->resolveServiceToken($bearer);
        }

        if (str_starts_with($bearer, 'ek_')) {
            return $this->resolveApiKey($bearer);
        }

        return null;
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
}
