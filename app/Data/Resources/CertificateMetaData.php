<?php

namespace App\Data\Resources;

use App\Models\IssuerSecret;
use Spatie\LaravelData\Data;

class CertificateMetaData extends Data
{
    public function __construct(
        public ?string $subject,
        public ?string $serial,
        public ?string $fingerprint,
        public ?string $not_before,
        public ?string $not_after,
    ) {}

    public static function fromSecret(IssuerSecret $secret): self
    {
        return new self(
            subject: $secret->cert_subject,
            serial: $secret->cert_serial,
            fingerprint: $secret->cert_fingerprint,
            not_before: $secret->cert_not_before?->toIso8601String(),
            not_after: $secret->cert_not_after?->toIso8601String(),
        );
    }
}
