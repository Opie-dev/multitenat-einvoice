<?php

namespace App\Data\Resources;

use App\Models\Issuer;
use Spatie\LaravelData\Data;

class IssuerData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $tin,
        public string $id_type,
        public string $id_number,
        public ?string $sst_number,
        public ?string $tourism_tax_number,
        public string $msic_code,
        public string $business_activity_description,
        public AddressData $address,
        public string $email,
        public string $phone,
        public string $environment,
        public string $lhdn_mode,
        public bool $einvoice_required,
        public bool $consolidation_enabled,
        public string $status,
        public bool $has_credentials,
        public bool $has_certificate,
        public ?CertificateMetaData $certificate,
        public ?string $tin_verified_at,
        public ?string $authorized_at,
        public ?string $activated_at,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Issuer $issuer): self
    {
        $secret = $issuer->relationLoaded('secret') ? $issuer->secret : $issuer->secret()->first();
        $hasCertificate = $secret?->hasCertificate() ?? false;

        return new self(
            id: $issuer->id,
            name: $issuer->name,
            tin: $issuer->tin,
            id_type: $issuer->id_type->value,
            id_number: $issuer->id_number,
            sst_number: $issuer->sst_number,
            tourism_tax_number: $issuer->tourism_tax_number,
            msic_code: $issuer->msic_code,
            business_activity_description: $issuer->business_activity_description,
            address: new AddressData(
                line1: $issuer->address_line1,
                line2: $issuer->address_line2,
                line3: $issuer->address_line3,
                postcode: $issuer->postcode,
                city: $issuer->city,
                state_code: $issuer->state_code,
                country_code: $issuer->country_code,
            ),
            email: $issuer->email,
            phone: $issuer->phone,
            environment: $issuer->environment->value,
            lhdn_mode: $issuer->lhdn_mode->value,
            einvoice_required: $issuer->einvoice_required,
            consolidation_enabled: $issuer->consolidation_enabled,
            status: $issuer->status->value,
            has_credentials: $secret?->hasCredentials() ?? false,
            has_certificate: $hasCertificate,
            certificate: $hasCertificate ? CertificateMetaData::fromSecret($secret) : null,
            tin_verified_at: $issuer->tin_verified_at?->toIso8601String(),
            authorized_at: $issuer->authorized_at?->toIso8601String(),
            activated_at: $issuer->activated_at?->toIso8601String(),
            created_at: $issuer->created_at->toIso8601String(),
            updated_at: $issuer->updated_at->toIso8601String(),
        );
    }
}
