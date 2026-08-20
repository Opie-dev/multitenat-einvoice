<?php

namespace App\Actions\Documents;

use App\Data\Requests\Documents\CreateDocumentData;
use App\Enums\Environment;
use App\Models\Document;
use App\Models\Issuer;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * One-click "sandbox test" step of the onboarding wizard: creates and
 * submits a fixed sample invoice for the issuer via the existing
 * CreateDocument pipeline, so it is validated, totalled, and routed exactly
 * like a real document. Always runs against the sandbox environment,
 * whatever environment the caller's session/context is currently in —
 * mirrors the context snapshot/restore pattern used by the console sweeps
 * (see App\Console\Commands\ConsolidateDocuments).
 */
final class CreateSandboxTestDocument
{
    public function __construct(
        private readonly CreateDocument $createDocument,
        private readonly TenantContext $context,
    ) {}

    public function handle(Issuer $issuer): Document
    {
        $callerTenant = $this->context->tenantOrNull();
        $callerActor = $this->context->actor();
        $callerEnvironment = $this->context->has() ? $this->context->environment() : null;

        $this->context->bind($issuer->tenant, $callerActor, Environment::Sandbox);

        try {
            $data = CreateDocumentData::from([
                'type' => 'invoice',
                'issuer_id' => $issuer->id,
                'buyer' => [
                    'general_public' => false,
                    'name' => 'Sandbox Test Buyer',
                    'tin' => 'EI00000000010',
                ],
                'lines' => [[
                    'classification_code' => '022',
                    'description' => 'Dashboard sandbox test',
                    'quantity' => 1,
                    'unit_code' => 'C62',
                    'unit_price' => '10.00',
                    'tax_type' => 'E',
                    'tax_exemption_reason' => 'Dashboard onboarding sandbox test document.',
                ]],
                'source' => ['system' => 'dashboard-test', 'ref' => 'dashtest-'.Str::ulid()],
                'currency' => 'MYR',
                'consolidate' => false,
                'submit' => true,
            ]);

            return $this->createDocument->handle($data)->document;
        } finally {
            if ($callerTenant !== null && $callerEnvironment !== null) {
                $this->context->bind($callerTenant, $callerActor, $callerEnvironment);
            } else {
                $this->context->clear();
            }
        }
    }
}
