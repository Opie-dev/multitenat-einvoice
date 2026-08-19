<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\CreateIssuerData;
use App\Data\Requests\UpdateIssuerData;
use App\Data\Resources\IssuerData;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\Issuer;
use App\Tenancy\TenantContext;
use Spatie\LaravelData\CursorPaginatedDataCollection;

class IssuerController extends Controller
{
    /** @return CursorPaginatedDataCollection<int, IssuerData> */
    public function index(): CursorPaginatedDataCollection
    {
        return IssuerData::collect(
            Issuer::forCurrentEnvironment()->with('secret')->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(50),
            CursorPaginatedDataCollection::class,
        );
    }

    public function store(CreateIssuerData $data, TenantContext $context): IssuerData
    {
        if (Issuer::forCurrentEnvironment()->where('tin', $data->tin)->exists()) {
            throw ProblemException::conflict('An issuer with this TIN already exists in this environment.', 'issuer_exists');
        }
        $issuer = Issuer::create($data->toArray() + [
            'environment' => $context->environment(),
            'status' => IssuerStatus::Draft,
        ]);

        return IssuerData::fromModel($issuer)->wrap('data');
    }

    public function show(Issuer $issuer): IssuerData
    {
        return IssuerData::fromModel($issuer->load('secret'))->wrap('data');
    }

    public function update(UpdateIssuerData $data, Issuer $issuer): IssuerData
    {
        $issuer->update($data->toArray());

        return IssuerData::fromModel($issuer->refresh()->load('secret'))->wrap('data');
    }
}
