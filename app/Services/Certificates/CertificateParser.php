<?php

namespace App\Services\Certificates;

use Carbon\CarbonImmutable;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

class CertificateParser
{
    public function fromPem(string $certPem, string $keyPem, ?string $passphrase): CertificateInfo
    {
        $cert = @openssl_x509_read($certPem);
        if ($cert === false) {
            throw InvalidCertificate::because('certificate_unreadable');
        }
        $key = @openssl_pkey_get_private($keyPem, $passphrase ?? '');
        if ($key === false) {
            throw InvalidCertificate::because('key_unreadable');
        }

        return $this->build($cert, $key);
    }

    public function fromPkcs12(string $p12Binary, string $passphrase): CertificateInfo
    {
        $bundle = [];
        if (! @openssl_pkcs12_read($p12Binary, $bundle, $passphrase)) {
            throw InvalidCertificate::because('certificate_unreadable');
        }
        $cert = @openssl_x509_read($bundle['cert']);
        $key = @openssl_pkey_get_private($bundle['pkey']);
        if ($cert === false || $key === false) {
            throw InvalidCertificate::because('certificate_unreadable');
        }

        return $this->build($cert, $key);
    }

    private function build(OpenSSLCertificate $cert, OpenSSLAsymmetricKey $key): CertificateInfo
    {
        if (! openssl_x509_check_private_key($cert, $key)) {
            throw InvalidCertificate::because('key_mismatch');
        }
        $parsed = openssl_x509_parse($cert);
        if ($parsed === false) {
            throw InvalidCertificate::because('certificate_unreadable');
        }
        $notBefore = CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t']);
        $notAfter = CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']);
        if ($notAfter->isPast()) {
            throw InvalidCertificate::because('certificate_expired');
        }
        if ($notBefore->isFuture()) {
            throw InvalidCertificate::because('certificate_not_yet_valid');
        }

        $certPem = '';
        if (! openssl_x509_export($cert, $certPem) || $certPem === '') {
            throw InvalidCertificate::because('certificate_unreadable');
        }

        $keyPem = '';
        // On some Windows PHP builds, the openssl extension resolves its default
        // config at module init time, before the OPENSSL_CONF environment
        // variable set by the test bootstrap takes effect, so
        // openssl_pkey_export() fails with a "configuration file routines"
        // error even though OPENSSL_CONF is set by request time. Passing the
        // config path explicitly sidesteps that timing issue; on
        // Linux/production, where it's unset (or points nowhere), this is a
        // no-op and OpenSSL's own default config is used.
        $opensslConf = config('services.openssl_conf');
        $exportOptions = is_string($opensslConf) && $opensslConf !== '' && is_file($opensslConf)
            ? ['config' => $opensslConf]
            : [];
        // Unencrypted PEM; encrypted at rest by the model cast.
        if (! openssl_pkey_export($key, $keyPem, null, $exportOptions) || $keyPem === '') {
            throw InvalidCertificate::because('key_unreadable');
        }

        $fingerprint = openssl_x509_fingerprint($cert, 'sha256') ?: '';

        return new CertificateInfo(
            certPem: $certPem,
            keyPem: $keyPem,
            subject: $parsed['name'] ?? '',
            serial: (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            fingerprint: strtolower($fingerprint),
            notBefore: $notBefore,
            notAfter: $notAfter,
        );
    }
}
