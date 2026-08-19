<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Issuers\AuthorizeIssuer;
use App\Actions\Issuers\VerifyIssuerTin;
use App\Data\Resources\IssuerData;
use App\Http\Controllers\Controller;
use App\Models\Issuer;
use Illuminate\Http\JsonResponse;

class IssuerOnboardingController extends Controller
{
    public function verifyTin(Issuer $issuer, VerifyIssuerTin $verify): JsonResponse
    {
        return response()->json(['data' => IssuerData::fromModel($verify->handle($issuer)->load('secret'))->toArray()]);
    }

    public function authorize(Issuer $issuer, AuthorizeIssuer $authorize): JsonResponse
    {
        return response()->json(['data' => IssuerData::fromModel($authorize->handle($issuer)->load('secret'))->toArray()]);
    }
}
