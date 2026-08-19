<?php

use App\Enums\IssuerStatus;
use App\Models\Issuer;
use App\Services\Issuers\IssuerActivator;

it('activates an authorized issuer that has a valid certificate', function () {
    $issuer = new Issuer(['status' => IssuerStatus::Authorized, 'certificate_valid_until' => now()->addYear()]);
    expect((new IssuerActivator)->evaluate($issuer))->toBe(IssuerStatus::Active);
});

it('keeps an authorized issuer without certificate as authorized', function () {
    $issuer = new Issuer(['status' => IssuerStatus::Authorized, 'certificate_valid_until' => null]);
    expect((new IssuerActivator)->evaluate($issuer))->toBe(IssuerStatus::Authorized);
});

it('suspends an active issuer whose certificate expired', function () {
    $issuer = new Issuer(['status' => IssuerStatus::Active, 'certificate_valid_until' => now()->subDay()]);
    expect((new IssuerActivator)->evaluate($issuer))->toBe(IssuerStatus::Suspended);
});

it('leaves draft and tin_verified untouched', function () {
    foreach ([IssuerStatus::Draft, IssuerStatus::TinVerified] as $status) {
        $issuer = new Issuer(['status' => $status, 'certificate_valid_until' => now()->addYear()]);
        expect((new IssuerActivator)->evaluate($issuer))->toBe($status);
    }
});
