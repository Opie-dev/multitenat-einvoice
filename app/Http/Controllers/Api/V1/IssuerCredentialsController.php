<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\PutIssuerCredentialsData;
use App\Data\Resources\IssuerData;
use App\Enums\LhdnMode;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\Issuer;

class IssuerCredentialsController extends Controller
{
    public function update(PutIssuerCredentialsData $data, Issuer $issuer): IssuerData
    {
        if ($issuer->lhdn_mode !== LhdnMode::OwnCredentials) {
            throw ProblemException::conflict('Credentials only apply to issuers in own_credentials mode.', 'credentials_not_applicable');
        }
        $secret = $issuer->secret()->firstOrNew([]);
        $secret->fill([
            'lhdn_client_id' => $data->client_id,
            'lhdn_client_secret' => $data->client_secret,
            'credentials_verified_at' => null,
        ]);
        $secret->save();

        return IssuerData::fromModel($issuer->refresh()->load('secret'))->wrap('data');
    }
}
