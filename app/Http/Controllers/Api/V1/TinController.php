<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tin\ValidateTin;
use App\Data\Requests\Tin\ValidateTinData;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TinController extends Controller
{
    public function validate(ValidateTinData $data, ValidateTin $action): JsonResponse
    {
        return response()->json(['data' => $action->handle($data)->toArray()]);
    }
}
