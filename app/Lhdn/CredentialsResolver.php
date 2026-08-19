<?php

namespace App\Lhdn;

use App\Enums\Environment;
use App\Enums\LhdnMode;
use App\Models\Issuer;

class CredentialsResolver
{
    public function for(Issuer $issuer): LhdnCredentials
    {
        if ($issuer->lhdn_mode === LhdnMode::OwnCredentials) {
            $secret = $issuer->secret;
            if ($secret === null || ! $secret->hasCredentials()) {
                throw LhdnException::auth('Issuer LHDN credentials are missing.');
            }

            return new LhdnCredentials((string) $secret->lhdn_client_id, (string) $secret->lhdn_client_secret, null, 'own');
        }
        $base = $this->forIntermediary($issuer->environment);

        return new LhdnCredentials($base->clientId, $base->clientSecret, $issuer->tin, 'intermediary');
    }

    public function forIntermediary(Environment $environment): LhdnCredentials
    {
        $cfg = (array) config("lhdn.intermediary.{$environment->value}", []);
        $id = (string) ($cfg['client_id'] ?? '');
        $secret = (string) ($cfg['client_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw LhdnException::auth("Intermediary LHDN credentials are not configured for {$environment->value}.");
        }

        return new LhdnCredentials($id, $secret, null, 'intermediary');
    }
}
