<?php

use App\Models\IssuerSecret;

it('treats blank credentials as absent', function () {
    expect((new IssuerSecret)->hasCredentials())->toBeFalse()
        ->and((new IssuerSecret(['lhdn_client_id' => 'cid', 'lhdn_client_secret' => '']))->hasCredentials())->toBeFalse()
        ->and((new IssuerSecret(['lhdn_client_id' => 'cid', 'lhdn_client_secret' => 'sec']))->hasCredentials())->toBeTrue();
});

it('treats a blank signing key or certificate as no certificate', function () {
    expect((new IssuerSecret(['signing_certificate' => 'cert', 'signing_key' => '']))->hasCertificate())->toBeFalse()
        ->and((new IssuerSecret(['signing_certificate' => '', 'signing_key' => 'key']))->hasCertificate())->toBeFalse()
        ->and((new IssuerSecret(['signing_certificate' => 'cert', 'signing_key' => 'key']))->hasCertificate())->toBeTrue();
});
