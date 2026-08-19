<?php

use App\Lhdn\LhdnException;
use App\Lhdn\Signing\DocumentSigner;
use App\Lhdn\Signing\SigningMaterial;
use Carbon\CarbonImmutable;

$fx = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

it('signs a UBL document and the signature verifies against the certificate', function () use ($fx) {
    $ubl = ['_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', '_A' => 'a', '_B' => 'b', 'Invoice' => [['ID' => [['_' => 'INV-1']], 'IssueDate' => [['_' => '2026-08-20']]]]];
    $signer = new DocumentSigner;
    $signed = $signer->sign($ubl, new SigningMaterial($fx('test-cert.pem'), $fx('test-key.pem')), CarbonImmutable::parse('2026-08-20T03:04:05Z'));
    $inv = $signed->document['Invoice'][0];
    expect(array_key_first($inv))->toBe('UBLExtensions')
        ->and($inv['Signature'][0]['ID'][0]['_'])->toBe('urn:oasis:names:specification:ubl:signature:Invoice')
        ->and($signed->hashHex)->toBe(hash('sha256', $signed->json))
        ->and(strlen($signed->json))->toBeGreaterThan(100);
    $sig = $inv['UBLExtensions'][0]['UBLExtension'][0]['ExtensionContent'][0]['UBLDocumentSignatures'][0]['SignatureInformation'][0]['Signature'];
    expect($sig['SignedInfo'][0]['Reference'][1]['DigestValue'][0]['_'])
        ->toBe(base64_encode(hash('sha256', json_encode($ubl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true)));
    expect($sig['Object'][0]['QualifyingProperties'][0]['SignedProperties'][0]['SignedSignatureProperties'][0]['SigningTime'][0]['_'])->toBe('2026-08-20T03:04:05Z');
    $certDer = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $fx('test-cert.pem')) ?? '', true);
    expect($sig['KeyInfo'][0]['X509Data'][0]['X509Certificate'][0]['_'])->toBe(base64_encode((string) $certDer));
    expect($signer->verify($signed->document, $fx('test-cert.pem')))->toBeTrue();
});

it('detects tampering and rejects mismatched material', function () use ($fx) {
    $ubl = ['_D' => 'x', '_A' => 'a', '_B' => 'b', 'Invoice' => [['ID' => [['_' => 'INV-1']]]]];
    $signer = new DocumentSigner;
    $signed = $signer->sign($ubl, new SigningMaterial($fx('test-cert.pem'), $fx('test-key.pem')));
    $tampered = $signed->document;
    $tampered['Invoice'][0]['ID'][0]['_'] = 'INV-2';
    expect($signer->verify($tampered, $fx('test-cert.pem')))->toBeFalse();
    expect(fn () => $signer->sign($ubl, new SigningMaterial($fx('test-cert.pem'), $fx('other-key.pem'))))->toThrow(LhdnException::class);
});
