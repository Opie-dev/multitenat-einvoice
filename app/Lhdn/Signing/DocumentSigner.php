<?php

namespace App\Lhdn\Signing;

use App\Lhdn\LhdnException;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;

class DocumentSigner
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private const SHA256_URI = 'http://www.w3.org/2001/04/xmlenc#sha256';

    /** @param array<string, mixed> $ubl */
    public function sign(array $ubl, SigningMaterial $material, ?CarbonImmutable $signingTime = null): SignedDocument
    {
        $cert = @openssl_x509_read($material->certPem);
        $key = @openssl_pkey_get_private($material->keyPem);
        if ($cert === false || $key === false) {
            throw LhdnException::auth('Signing material could not be read.');
        }
        if (! openssl_x509_check_private_key($cert, $key)) {
            throw LhdnException::auth('Signing key does not match the certificate.');
        }

        /** @var array<string, mixed> $invoice */
        $invoice = $ubl['Invoice'][0];
        unset($invoice['UBLExtensions'], $invoice['Signature']);
        $bare = $ubl;
        $bare['Invoice'] = [$invoice];
        $docJson = self::encode($bare);
        $docDigest = base64_encode(hash('sha256', $docJson, true));
        $signature = '';
        if (! openssl_sign($docJson, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw LhdnException::auth('Signing failed: '.(openssl_error_string() ?: 'unknown error'));
        }
        $sig = base64_encode($signature);

        $der = self::der($material->certPem);
        $certDigest = base64_encode(hash('sha256', $der, true));
        $parsed = openssl_x509_parse($cert) ?: [];
        /** @var array<string, mixed> $issuerParts */
        $issuerParts = (array) ($parsed['issuer'] ?? []);
        /** @var array<string, mixed> $subjectParts */
        $subjectParts = (array) ($parsed['subject'] ?? []);
        $issuerName = self::dn($issuerParts);
        $subjectName = self::dn($subjectParts);
        $serial = self::serialDecimal($parsed);
        $time = ($signingTime ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s\Z');

        $signedProps = [[
            'Id' => 'id-xades-signed-props',
            'SignedSignatureProperties' => [[
                'SigningTime' => [['_' => $time]],
                'SigningCertificate' => [['Cert' => [[
                    'CertDigest' => [['DigestMethod' => [['_' => '', 'Algorithm' => self::SHA256_URI]], 'DigestValue' => [['_' => $certDigest]]]],
                    'IssuerSerial' => [['X509IssuerName' => [['_' => $issuerName]], 'X509SerialNumber' => [['_' => $serial]]]],
                ]]]],
            ]],
        ]];
        $qualifying = [['Target' => 'signature', 'SignedProperties' => $signedProps]];
        $propsDigest = base64_encode(hash('sha256', self::encode($qualifying[0]), true));

        $signatureObject = [
            'Id' => 'signature',
            'Object' => [['QualifyingProperties' => $qualifying]],
            'KeyInfo' => [['X509Data' => [[
                'X509Certificate' => [['_' => base64_encode($der)]],
                'X509SubjectName' => [['_' => $subjectName]],
                'X509IssuerSerial' => [['X509IssuerName' => [['_' => $issuerName]], 'X509SerialNumber' => [['_' => $serial]]]],
            ]]]],
            'SignatureValue' => [['_' => $sig]],
            'SignedInfo' => [[
                'SignatureMethod' => [['_' => '', 'Algorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256']],
                'Reference' => [
                    ['Type' => 'http://uri.etsi.org/01903/v1.3.2#SignedProperties', 'URI' => '#id-xades-signed-props', 'DigestMethod' => [['_' => '', 'Algorithm' => self::SHA256_URI]], 'DigestValue' => [['_' => $propsDigest]]],
                    ['Type' => '', 'URI' => '', 'DigestMethod' => [['_' => '', 'Algorithm' => self::SHA256_URI]], 'DigestValue' => [['_' => $docDigest]]],
                ],
            ]],
        ];
        $extensions = [['UBLExtension' => [['ExtensionURI' => [['_' => 'urn:oasis:names:specification:ubl:dsig:enveloped:xades']], 'ExtensionContent' => [['UBLDocumentSignatures' => [['SignatureInformation' => [[
            'ID' => [['_' => 'urn:oasis:names:specification:ubl:signature:1']],
            'ReferencedSignatureID' => [['_' => 'urn:oasis:names:specification:ubl:signature:Invoice']],
            'Signature' => $signatureObject,
        ]]]]]]]]]];

        $signedInvoice = ['UBLExtensions' => $extensions] + $invoice;
        $signedInvoice['Signature'] = [['ID' => [['_' => 'urn:oasis:names:specification:ubl:signature:Invoice']], 'SignatureMethod' => [['_' => 'urn:oasis:names:specification:ubl:dsig:enveloped:xades']]]];
        $out = $ubl;
        $out['Invoice'] = [$signedInvoice];
        $json = self::encode($out);

        return new SignedDocument($out, $json, hash('sha256', $json));
    }

    /** @param array<string, mixed> $signedDocument */
    public function verify(array $signedDocument, string $certPem): bool
    {
        $invoice = $signedDocument['Invoice'][0] ?? null;
        if (! is_array($invoice)) {
            return false;
        }
        /** @var array<string, mixed> $invoice */
        $sig = $invoice['UBLExtensions'][0]['UBLExtension'][0]['ExtensionContent'][0]['UBLDocumentSignatures'][0]['SignatureInformation'][0]['Signature'] ?? null;
        if (! is_array($sig)) {
            return false;
        }
        /** @var array<string, mixed> $sig */
        unset($invoice['UBLExtensions'], $invoice['Signature']);
        $bare = $signedDocument;
        $bare['Invoice'] = [$invoice];
        $docJson = self::encode($bare);
        $expectedDigest = base64_encode(hash('sha256', $docJson, true));
        if (($sig['SignedInfo'][0]['Reference'][1]['DigestValue'][0]['_'] ?? null) !== $expectedDigest) {
            return false;
        }
        $props = $sig['Object'][0]['QualifyingProperties'][0] ?? null;
        if (! is_array($props) || ($sig['SignedInfo'][0]['Reference'][0]['DigestValue'][0]['_'] ?? null) !== base64_encode(hash('sha256', self::encode($props), true))) {
            return false;
        }
        $pub = openssl_pkey_get_public($certPem);
        if ($pub === false) {
            return false;
        }
        $signature = base64_decode((string) ($sig['SignatureValue'][0]['_'] ?? ''), true);

        return $signature !== false && openssl_verify($docJson, $signature, $pub, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @param array<string, mixed> $value */
    public static function encode(array $value): string
    {
        return (string) json_encode($value, self::JSON_FLAGS);
    }

    private static function der(string $pem): string
    {
        $body = preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($body, true);
        if ($der === false) {
            throw LhdnException::auth('Certificate PEM could not be decoded.');
        }

        return $der;
    }

    /**
     * XAdES `X509SerialNumber` is `xsd:integer` (decimal). `openssl_x509_parse()['serialNumber']`
     * is a `0x…` hex string once the serial no longer fits a PHP int (true for our test cert and
     * for real CA-issued certs), so derive the decimal from `serialNumberHex` via brick/math.
     *
     * @param  array<string, mixed>  $parsed
     */
    private static function serialDecimal(array $parsed): string
    {
        $hex = $parsed['serialNumberHex'] ?? null;
        if (is_string($hex) && $hex !== '') {
            $hex = preg_replace('/^0x/i', '', $hex) ?? $hex;

            return BigInteger::fromBase($hex, 16)->toBase(10);
        }

        $serial = (string) ($parsed['serialNumber'] ?? '');
        if (ctype_digit($serial)) {
            return $serial;
        }

        throw LhdnException::auth('Certificate serial number could not be parsed.');
    }

    /** @param array<string, mixed> $parts */
    private static function dn(array $parts): string
    {
        // OpenSSL gives ['C' => 'MY', 'O' => '…', 'CN' => '…'] in cert order; XAdES/RFC 2253 string lists CN first.
        $order = ['CN', 'OU', 'O', 'L', 'ST', 'C', 'emailAddress'];
        $out = [];
        foreach ($order as $k) {
            if (isset($parts[$k])) {
                foreach ((array) $parts[$k] as $v) {
                    $out[] = "{$k}={$v}";
                }
            }
        }
        foreach ($parts as $k => $v) {
            if (! in_array($k, $order, true)) {
                foreach ((array) $v as $vv) {
                    $out[] = "{$k}={$vv}";
                }
            }
        }

        return implode(', ', $out);
    }
}
