<?php

namespace App\Actions\Issuers;

use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Exceptions\ProblemException;
use App\Lhdn\LhdnClientFactory;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Services\Audit\AuditLogger;
use App\Services\Issuers\IssuerActivator;

class AuthorizeIssuer
{
    public function __construct(private readonly LhdnClientFactory $clients, private readonly IssuerActivator $activator, private readonly AuditLogger $audit) {}

    public function handle(Issuer $issuer): Issuer
    {
        if ($issuer->tin_verified_at === null) {
            throw ProblemException::conflict('Verify the issuer TIN before authorising LHDN access.', 'tin_not_verified');
        }
        try {
            $this->clients->for($issuer)->token($issuer);
        } catch (LhdnException $e) {
            if ($e->kind === LhdnErrorKind::Auth && $issuer->lhdn_mode === LhdnMode::Intermediary) {
                throw LhdnException::auth($e->getMessage().' Ask the merchant to grant Billplz intermediary access to this TIN in MyInvois, then retry.', $e->httpStatus);
            }
            throw $e;
        }
        $issuer->authorized_at = now();
        if (in_array($issuer->status, [IssuerStatus::Draft, IssuerStatus::TinVerified], true)) {
            $issuer->status = IssuerStatus::Authorized;
        }
        $issuer->save();
        $issuer->secret?->forceFill(['credentials_verified_at' => now()])->save();
        $this->activator->apply($issuer);
        $this->audit->record('issuer.authorized', $issuer, ['lhdn_mode' => $issuer->lhdn_mode->value, 'status' => $issuer->status->value]);

        return $issuer->refresh();
    }
}
