<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Requests\CreateIssuerData;
use App\Data\Requests\UpdateIssuerData;
use App\Data\Resources\IssuerData;
use App\Enums\IssuerStatus;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Controller;
use App\Models\Issuer;
use App\Services\Audit\AuditLogger;
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

    public function store(CreateIssuerData $data, TenantContext $context, AuditLogger $audit): IssuerData
    {
        if (Issuer::forCurrentEnvironment()->where('tin', $data->tin)->exists()) {
            throw ProblemException::conflict('An issuer with this TIN already exists in this environment.', 'issuer_exists');
        }
        $issuer = Issuer::create($data->toArray() + [
            'environment' => $context->environment(),
            'status' => IssuerStatus::Draft,
        ]);

        $audit->record('issuer.created', $issuer);

        return IssuerData::fromModel($issuer)->wrap('data');
    }

    public function show(Issuer $issuer): IssuerData
    {
        return IssuerData::fromModel($issuer->load('secret'))->wrap('data');
    }

    public function update(UpdateIssuerData $data, Issuer $issuer, AuditLogger $audit): IssuerData
    {
        // Captured before update(): Model::save() syncs the original attributes
        // to the new values before update() returns, so getOriginal() must be
        // snapshotted here to recover the pre-update "from" values for the diff.
        $original = $issuer->getOriginal();

        $issuer->update($data->toArray());

        $audit->record('issuer.updated', $issuer, AuditLogger::diff($issuer, $original));

        return IssuerData::fromModel($issuer->refresh()->load('secret'))->wrap('data');
    }
}
