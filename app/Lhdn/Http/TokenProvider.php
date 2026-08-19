<?php

namespace App\Lhdn\Http;

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\LhdnCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class TokenProvider
{
    /** @param callable(): AccessToken $fetch */
    public function get(Environment $env, LhdnCredentials $creds, callable $fetch): AccessToken
    {
        $key = $this->key($env, $creds);
        $margin = (int) config('lhdn.token_ttl_margin_seconds', 60);
        if ($cached = $this->read($key, $margin)) {
            return $cached;
        }

        return Cache::lock($key.':lock', 10)->block(5, function () use ($key, $margin, $fetch): AccessToken {
            if ($cached = $this->read($key, $margin)) {
                return $cached;
            }
            $token = $fetch();
            $ttl = max(1, $token->expiresAt->getTimestamp() - time() - $margin);
            Cache::put($key, ['token' => $token->token, 'expires_at' => $token->expiresAt->getTimestamp()], $ttl);

            return $token;
        });
    }

    public function forget(Environment $env, LhdnCredentials $creds): void
    {
        Cache::forget($this->key($env, $creds));
    }

    private function read(string $key, int $margin): ?AccessToken
    {
        /** @var array{token: string, expires_at: int}|null $raw */
        $raw = Cache::get($key);
        if ($raw === null) {
            return null;
        }
        $token = new AccessToken($raw['token'], CarbonImmutable::createFromTimestamp($raw['expires_at']));

        return $token->isExpired($margin) ? null : $token;
    }

    private function key(Environment $env, LhdnCredentials $creds): string
    {
        return "lhdn:token:{$env->value}:{$creds->cacheKeyPart()}";
    }
}
