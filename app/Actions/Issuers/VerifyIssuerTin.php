<?php

namespace App\Actions\Issuers;

use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Exceptions\ProblemException;
use App\Lhdn\CredentialsResolver;
use App\Lhdn\LhdnClientFactory;
use App\Models\Issuer;
use App\Services\Audit\AuditLogger;

class VerifyIssuerTin
{
    public function __construct(
        private readonly LhdnClientFactory $clients,
        private readonly CredentialsResolver $credentials,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Issuer $issuer): Issuer
    {
        if ($issuer->lhdn_mode === LhdnMode::OwnCredentials) {
            // The fake driver (used in tests) bypasses credential resolution
            // entirely, so validate presence here rather than relying on
            // HttpLhdnClient::make() to do it — this must fail the same way
            // regardless of which driver is configured.
            $this->credentials->for($issuer);
        }
        $client = $issuer->lhdn_mode === LhdnMode::OwnCredentials
            ? $this->clients->for($issuer)
            : $this->clients->forEnvironment($issuer->environment);
        $valid = $client->validateTin($issuer->environment, $issuer->tin, $issuer->id_type->value, $issuer->id_number, $issuer);
        $this->audit->record('issuer.tin_verified', $issuer, ['tin_verified' => $valid]);
        if (! $valid) {
            throw new ProblemException(422, 'Unprocessable Entity', 'LHDN does not recognise this TIN / ID combination.', 'tin_invalid');
        }
        $issuer->tin_verified_at = now();
        if ($issuer->status === IssuerStatus::Draft) {
            $issuer->status = IssuerStatus::TinVerified;
        }
        $issuer->save();

        return $issuer->refresh();
    }
}
