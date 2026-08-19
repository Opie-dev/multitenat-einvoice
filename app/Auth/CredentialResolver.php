<?php

namespace App\Auth;

use App\Models\ServiceToken;

class CredentialResolver
{
    public function resolve(string $bearer): ?ResolvedCredential
    {
        if (str_starts_with($bearer, 'sk_')) {
            return $this->resolveServiceToken($bearer);
        }

        return null; // 'ek_' API keys are added in Task 5
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
}
