<?php

use App\Actions\Documents\CreateDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Enums\Environment;
use App\Lhdn\Ubl\UblDocumentBuilder;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-08-20 03:04:05');
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create([
        'name' => 'Vendor One Sdn Bhd', 'tin' => 'C12345678901', 'id_number' => '202001012345', 'sst_number' => 'W10-1808-32000001',
        'msic_code' => '47911', 'business_activity_description' => 'Retail sale via internet', 'address_line1' => '1 Jalan Test',
        'postcode' => '50000', 'city' => 'Kuala Lumpur', 'state_code' => '14', 'email' => 'vendor@example.com', 'phone' => '+60123456789',
    ]);
});
afterEach(fn () => Carbon::setTestNow());

function ublDoc(Issuer $issuer, array $overrides = []): Document
{
    $payload = array_replace_recursive([
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true], 'issue_date' => '2026-08-20', 'submit' => false,
        'lines' => [
            ['classification_code' => '022', 'description' => 'Widget', 'quantity' => 2, 'unit_code' => 'C62', 'unit_price' => '10.50', 'tax_type' => '02', 'tax_rate' => 6],
            ['classification_code' => '022', 'description' => 'Exempt thing', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => '5', 'tax_type' => 'E', 'tax_exemption_reason' => 'Exempt goods', 'discount_amount' => '1.00'],
        ],
        'source' => ['system' => 'test', 'ref' => 'ref-'.bin2hex(random_bytes(3))],
    ], $overrides);
    $doc = app(CreateDocument::class)->handle(CreateDocumentData::from($payload))->document;
    // Deterministic, ULID-free internal id; unique per tenant (DB-enforced), so number
    // sequentially within the test's tenant instead of always reusing 'INV-0001'.
    // The document itself is already persisted at this point, so count() already includes it.
    $next = Document::query()->count();
    $doc->forceFill(['lhdn_internal_id' => sprintf('INV-%04d', $next)])->save();

    return $doc->refresh()->load('lines', 'issuer');
}

it('builds a v1.1 invoice with supplier, general-public buyer, lines, tax and monetary totals', function () {
    $ubl = (new UblDocumentBuilder)->build(ublDoc($this->issuer));
    $inv = $ubl['Invoice'][0];
    expect($ubl['_D'])->toBe('urn:oasis:names:specification:ubl:schema:xsd:Invoice-2')
        ->and($inv['ID'][0]['_'])->toBe('INV-0001')
        ->and($inv['IssueDate'][0]['_'])->toBe('2026-08-20')
        ->and($inv['IssueTime'][0]['_'])->toBe('03:04:05Z')
        ->and($inv['InvoiceTypeCode'][0])->toBe(['_' => '01', 'listVersionID' => '1.1'])
        ->and($inv['DocumentCurrencyCode'][0]['_'])->toBe('MYR')
        ->and($inv)->not->toHaveKey('TaxExchangeRate')
        ->and($inv['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0])->toBe(['_' => 'C12345678901', 'schemeID' => 'TIN'])
        ->and($inv['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'][1]['ID'][0])->toBe(['_' => '202001012345', 'schemeID' => 'BRN'])
        ->and($inv['AccountingSupplierParty'][0]['Party'][0]['PartyIdentification'][2]['ID'][0])->toBe(['_' => 'W10-1808-32000001', 'schemeID' => 'SST'])
        ->and($inv['AccountingCustomerParty'][0]['Party'][0]['PartyIdentification'][0]['ID'][0]['_'])->toBe('EI00000000010')
        ->and($inv['InvoiceLine'])->toHaveCount(2)
        ->and($inv['InvoiceLine'][0]['InvoicedQuantity'][0])->toBe(['_' => 2.0, 'unitCode' => 'C62'])
        ->and($inv['InvoiceLine'][0]['LineExtensionAmount'][0])->toBe(['_' => 21.0, 'currencyID' => 'MYR'])
        ->and($inv['InvoiceLine'][0]['TaxTotal'][0]['TaxSubtotal'][0]['TaxCategory'][0]['Percent'][0]['_'])->toBe(6.0)
        ->and($inv['InvoiceLine'][1]['AllowanceCharge'][0]['Amount'][0]['_'])->toBe(1.0)
        ->and($inv['InvoiceLine'][1]['TaxTotal'][0]['TaxSubtotal'][0]['TaxCategory'][0]['TaxExemptionReason'][0]['_'])->toBe('Exempt goods')
        ->and($inv['TaxTotal'][0]['TaxAmount'][0]['_'])->toBe(1.26)
        ->and($inv['LegalMonetaryTotal'][0]['PayableAmount'][0])->toBe(['_' => 26.26, 'currencyID' => 'MYR'])
        ->and($ubl)->not->toHaveKey('UBLExtensions');
    assertMatchesGolden('invoice-myr', $ubl);
});

it('adds BillingReference for notes and TaxExchangeRate for foreign currency; self-billed type code', function () {
    $orig = ublDoc($this->issuer);
    $orig->forceFill(['lhdn_uuid' => 'UUID-ORIG'])->save();
    $note = ublDoc($this->issuer, ['type' => 'credit_note', 'original_document_ref' => ['document_id' => $orig->id]]);
    $ublNote = (new UblDocumentBuilder)->build($note);
    expect($ublNote['Invoice'][0]['InvoiceTypeCode'][0]['_'])->toBe('02')
        ->and($ublNote['Invoice'][0]['BillingReference'][0]['InvoiceDocumentReference'][0]['ID'][0]['_'])->toBe('INV-0001')
        ->and($ublNote['Invoice'][0]['BillingReference'][0]['InvoiceDocumentReference'][0]['UUID'][0]['_'])->toBe('UUID-ORIG');
    assertMatchesGolden('credit-note', $ublNote);

    $usd = ublDoc($this->issuer, ['type' => 'self_billed_invoice', 'currency' => 'USD', 'exchange_rate' => '4.75', 'buyer' => ['general_public' => false, 'name' => 'Acme Inc', 'tin' => 'EI00000000020', 'id_type' => 'BRN', 'id_number' => 'NA', 'country_code' => 'USA']]);
    $ublUsd = (new UblDocumentBuilder)->build($usd);
    expect($ublUsd['Invoice'][0]['InvoiceTypeCode'][0]['_'])->toBe('11')
        ->and($ublUsd['Invoice'][0]['TaxExchangeRate'][0]['CalculationRate'][0]['_'])->toBe(4.75)
        ->and($ublUsd['Invoice'][0]['TaxCurrencyCode'][0]['_'])->toBe('MYR')
        ->and($ublUsd['Invoice'][0]['InvoiceLine'][0]['LineExtensionAmount'][0]['currencyID'])->toBe('USD');
    assertMatchesGolden('self-billed-usd', $ublUsd);
});

it('is deterministic', function () {
    $doc = ublDoc($this->issuer);
    expect((new UblDocumentBuilder)->build($doc))->toBe((new UblDocumentBuilder)->build($doc->fresh()->load('lines', 'issuer')));
});
