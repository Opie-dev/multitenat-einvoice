<?php

namespace App\Lhdn\Ubl;

use App\Domain\Documents\Money;
use App\Models\Document;
use App\Models\DocumentLine;
use Brick\Math\BigDecimal;

class UblDocumentBuilder
{
    public const TAX_SCHEME = ['ID' => [['_' => 'OTH', 'schemeID' => 'UN/ECE 5153', 'schemeAgencyID' => '6']]];

    /** @return array<string, mixed> */
    public function build(Document $document): array
    {
        $document->loadMissing('lines', 'issuer');
        $cur = $document->currency;
        $inv = [
            'ID' => [['_' => $document->lhdn_internal_id ?? $document->id]],
            'IssueDate' => [['_' => $document->issue_date->toDateString()]],
            'IssueTime' => [['_' => $document->created_at->copy()->utc()->format('H:i:s').'Z']],
            'InvoiceTypeCode' => [['_' => $document->type->lhdnCode(), 'listVersionID' => '1.1']],
            'DocumentCurrencyCode' => [['_' => $cur]],
            'TaxCurrencyCode' => [['_' => 'MYR']],
        ];
        if ($cur !== 'MYR' && $document->exchange_rate !== null) {
            $inv['TaxExchangeRate'] = [[
                'SourceCurrencyCode' => [['_' => $cur]],
                'TargetCurrencyCode' => [['_' => 'MYR']],
                'CalculationRate' => [['_' => self::num($document->exchange_rate)]],
            ]];
        }
        if ($document->type->requiresOriginalRef()) {
            $original = $document->originalDocument;
            $refId = $original !== null
                ? ($original->lhdn_internal_id ?? $original->id)
                : ($document->original_lhdn_uuid ?? '');
            $uuid = ($original !== null ? $original->lhdn_uuid : null) ?? $document->original_lhdn_uuid;
            $ref = ['ID' => [['_' => $refId]]];
            if ($uuid !== null) {
                $ref['UUID'] = [['_' => $uuid]];
            }
            $inv['BillingReference'] = [['InvoiceDocumentReference' => [$ref]]];
        }
        $inv['AccountingSupplierParty'] = [UblParty::supplier($document->issuer)];
        $inv['AccountingCustomerParty'] = [UblParty::buyer($document->buyer_snapshot)];

        /** @var array<string, array{tax_type: string, rate: ?string, taxable: BigDecimal, tax: BigDecimal, exemption: ?string}> $subtotals */
        $subtotals = [];
        $inv['InvoiceLine'] = $document->lines->map(function (DocumentLine $line) use ($cur, &$subtotals): array {
            $key = $line->tax_type.'|'.($line->tax_rate ?? '');
            $subtotals[$key] ??= ['tax_type' => $line->tax_type, 'rate' => $line->tax_rate, 'taxable' => BigDecimal::zero(), 'tax' => BigDecimal::zero(), 'exemption' => $line->tax_exemption_reason];
            $subtotals[$key]['taxable'] = $subtotals[$key]['taxable']->plus(Money::of($line->subtotal));
            $subtotals[$key]['tax'] = $subtotals[$key]['tax']->plus(Money::of($line->tax_amount));

            return $this->line($line, $cur);
        })->values()->all();

        $inv['TaxTotal'] = [[
            'TaxAmount' => [['_' => self::num($document->tax_total), 'currencyID' => $cur]],
            'TaxSubtotal' => array_values(array_map(fn (array $s) => [
                'TaxableAmount' => [['_' => self::num(Money::str($s['taxable'])), 'currencyID' => $cur]],
                'TaxAmount' => [['_' => self::num(Money::str($s['tax'])), 'currencyID' => $cur]],
                'TaxCategory' => [$this->taxCategory($s['tax_type'], $s['rate'], $s['exemption'])],
            ], $subtotals)),
        ]];
        $inv['LegalMonetaryTotal'] = [[
            'LineExtensionAmount' => [['_' => self::num($document->total_excluding_tax), 'currencyID' => $cur]],
            'TaxExclusiveAmount' => [['_' => self::num($document->total_excluding_tax), 'currencyID' => $cur]],
            'TaxInclusiveAmount' => [['_' => self::num($document->total_including_tax), 'currencyID' => $cur]],
            'AllowanceTotalAmount' => [['_' => self::num($document->discount_total), 'currencyID' => $cur]],
            'PayableAmount' => [['_' => self::num($document->total_payable), 'currencyID' => $cur]],
        ]];

        return [
            '_D' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            '_A' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            '_B' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            'Invoice' => [$inv],
        ];
    }

    /** @return array<string, mixed> */
    private function line(DocumentLine $line, string $cur): array
    {
        $gross = Money::round2(Money::of($line->quantity)->multipliedBy(Money::of($line->unit_price)));
        $out = [
            'ID' => [['_' => (string) $line->position]],
            'InvoicedQuantity' => [['_' => self::num($line->quantity), 'unitCode' => $line->unit_code]],
            'LineExtensionAmount' => [['_' => self::num($line->subtotal), 'currencyID' => $cur]],
        ];
        if (Money::of($line->discount_amount)->isPositive()) {
            $out['AllowanceCharge'] = [[
                'ChargeIndicator' => [['_' => false]],
                'AllowanceChargeReason' => [['_' => 'Discount']],
                'Amount' => [['_' => self::num($line->discount_amount), 'currencyID' => $cur]],
            ]];
        }
        $out['TaxTotal'] = [[
            'TaxAmount' => [['_' => self::num($line->tax_amount), 'currencyID' => $cur]],
            'TaxSubtotal' => [[
                'TaxableAmount' => [['_' => self::num($line->subtotal), 'currencyID' => $cur]],
                'TaxAmount' => [['_' => self::num($line->tax_amount), 'currencyID' => $cur]],
                'TaxCategory' => [$this->taxCategory($line->tax_type, $line->tax_rate, $line->tax_exemption_reason)],
            ]],
        ]];
        $out['Item'] = [[
            'CommodityClassification' => [['ItemClassificationCode' => [['_' => $line->classification_code, 'listID' => 'CLASS']]]],
            'Description' => [['_' => $line->description]],
        ]];
        $out['Price'] = [['PriceAmount' => [['_' => self::num($line->unit_price), 'currencyID' => $cur]]]];
        $out['ItemPriceExtension'] = [['Amount' => [['_' => self::num(Money::str($gross)), 'currencyID' => $cur]]]];

        return $out;
    }

    /** @return array<string, mixed> */
    private function taxCategory(string $taxType, ?string $rate, ?string $exemption): array
    {
        $cat = ['ID' => [['_' => $taxType]]];
        if ($rate !== null && $taxType !== 'E' && $taxType !== '06') {
            $cat['Percent'] = [['_' => self::num($rate)]];
        }
        if ($taxType === 'E' && $exemption !== null) {
            $cat['TaxExemptionReason'] = [['_' => $exemption]];
        }
        $cat['TaxScheme'] = [self::TAX_SCHEME];

        return $cat;
    }

    private static function num(string $decimal): float
    {
        return (float) $decimal;
    }
}
