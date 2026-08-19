<?php

namespace App\Data\Requests;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\RequiredIf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class PutIssuerCertificateData extends Data
{
    public function __construct(
        #[In(['pem', 'pkcs12'])] public string $format,
        #[RequiredIf('format', 'pem')] public ?string $certificate = null,
        #[RequiredIf('format', 'pem')] public ?string $private_key = null,
        #[RequiredIf('format', 'pkcs12')] public ?string $pkcs12 = null,
        #[RequiredIf('format', 'pkcs12')] public ?string $passphrase = null,
    ) {}

    /**
     * Properties with a null default are skipped by the rule inferrer when
     * absent from the payload, which would silently defeat #[RequiredIf] for
     * these four conditional props. Restate them explicitly so format=pem
     * without certificate/private_key, or format=pkcs12 without
     * pkcs12/passphrase, always 422s regardless of which side is missing.
     *
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'certificate' => ['required_if:format,pem', 'nullable', 'string'],
            'private_key' => ['required_if:format,pem', 'nullable', 'string'],
            'pkcs12' => ['required_if:format,pkcs12', 'nullable', 'string'],
            'passphrase' => ['required_if:format,pkcs12', 'nullable', 'string'],
        ];
    }
}
