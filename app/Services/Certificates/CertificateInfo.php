<?php

namespace App\Services\Certificates;

use Carbon\CarbonImmutable;

final class CertificateInfo
{
    public function __construct(
        public readonly string $certPem,
        public readonly string $keyPem,
        public readonly string $subject,
        public readonly string $serial,
        public readonly string $fingerprint,
        public readonly CarbonImmutable $notBefore,
        public readonly CarbonImmutable $notAfter,
    ) {}
}
