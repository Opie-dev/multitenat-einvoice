<?php

namespace App\Lhdn\Signing;

use App\Lhdn\LhdnException;
use App\Models\IssuerSecret;

final class SigningMaterial
{
    public function __construct(public readonly string $certPem, public readonly string $keyPem) {}

    public static function fromSecret(?IssuerSecret $secret): self
    {
        if ($secret === null || ! $secret->hasCertificate()) {
            throw LhdnException::auth('Issuer has no signing certificate.');
        }

        return new self((string) $secret->signing_certificate, (string) $secret->signing_key);
    }
}
