<?php

use App\Services\Certificates\CertificateParser;
use App\Services\Certificates\InvalidCertificate;
use Illuminate\Support\Carbon;

$fx = fn (string $f) => file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

it('parses a PEM certificate and key', function () use ($fx) {
    $info = (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('test-key.pem'), null);
    expect($info->subject)->toContain('CN=Test Issuer')
        ->and($info->fingerprint)->toMatch('/^[a-f0-9]{64}$/')
        ->and($info->notAfter->isFuture())->toBeTrue()
        ->and($info->keyPem)->toContain('PRIVATE KEY');
});

it('decrypts a passphrase-protected key and stores it unencrypted in memory', function () use ($fx) {
    $info = (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('test-key-encrypted.pem'), 'keypass');
    expect($info->keyPem)->not->toContain('ENCRYPTED');
});

it('rejects a key that does not match the certificate', function () use ($fx) {
    (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('other-key.pem'), null);
})->throws(InvalidCertificate::class, 'key_mismatch');

it('rejects garbage', function () {
    (new CertificateParser)->fromPem('nope', 'nope', null);
})->throws(InvalidCertificate::class, 'certificate_unreadable');

it('parses a PKCS#12 bundle', function () use ($fx) {
    $info = (new CertificateParser)->fromPkcs12($fx('test.p12'), 'secret');
    expect($info->subject)->toContain('CN=Test Issuer');
});

it('rejects a PKCS#12 bundle with the wrong passphrase', function () use ($fx) {
    (new CertificateParser)->fromPkcs12($fx('test.p12'), 'wrong');
})->throws(InvalidCertificate::class, 'certificate_unreadable');

it('rejects an expired certificate', function () use ($fx) {
    Carbon::setTestNow('2040-01-01');
    try {
        (new CertificateParser)->fromPem($fx('test-cert.pem'), $fx('test-key.pem'), null);
    } finally {
        Carbon::setTestNow();
    }
})->throws(InvalidCertificate::class, 'certificate_expired');
