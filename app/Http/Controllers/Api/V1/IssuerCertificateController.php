<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\PutIssuerCertificateData;
use App\Data\Resources\IssuerData;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\Issuer;
use App\Models\IssuerSecretHistory;
use App\Services\Certificates\CertificateParser;
use App\Services\Certificates\InvalidCertificate;
use App\Services\Issuers\IssuerActivator;
use Illuminate\Support\Facades\DB;

class IssuerCertificateController extends Controller
{
    public function update(
        PutIssuerCertificateData $data,
        Issuer $issuer,
        CertificateParser $parser,
        IssuerActivator $activator,
    ): IssuerData {
        try {
            $info = $data->format === 'pem'
                ? $parser->fromPem((string) $data->certificate, (string) $data->private_key, $data->passphrase)
                : $parser->fromPkcs12((string) base64_decode((string) $data->pkcs12, true), (string) $data->passphrase);
        } catch (InvalidCertificate $e) {
            throw new ProblemException(422, 'Unprocessable Entity', 'The certificate could not be accepted.', $e->getMessage());
        }

        DB::transaction(function () use ($issuer, $info): void {
            $secret = $issuer->secret()->firstOrNew([]);
            if ($secret->hasCertificate()) {
                IssuerSecretHistory::create([
                    'issuer_id' => $issuer->id,
                    'kind' => 'certificate',
                    'payload' => ['certificate' => $secret->signing_certificate, 'key' => $secret->signing_key],
                    'cert_fingerprint' => $secret->cert_fingerprint,
                    'replaced_at' => now(),
                ]);
            }
            $secret->fill([
                'signing_certificate' => $info->certPem,
                'signing_key' => $info->keyPem,
                'cert_subject' => $info->subject,
                'cert_serial' => $info->serial,
                'cert_fingerprint' => $info->fingerprint,
                'cert_not_before' => $info->notBefore,
                'cert_not_after' => $info->notAfter,
            ])->save();
            $issuer->forceFill(['certificate_valid_until' => $info->notAfter])->save();
        });

        $activator->apply($issuer);

        return IssuerData::fromModel($issuer->refresh()->load('secret'))->wrap('data');
    }
}
