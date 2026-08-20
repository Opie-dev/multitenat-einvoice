<?php

namespace App\Actions\Consolidation;

use App\Actions\Documents\CreateDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Domain\Documents\DocumentStateMachine;
use App\Domain\Documents\Money;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Issuer;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Folds one issuer's month of B2C receipts into consolidated invoices — one per
 * currency, each with a line per classification code — and links the receipts to
 * the parent they were reported under.
 *
 * The parent is created through the ordinary CreateDocument path, so it is
 * validated, totalled, queued and submitted exactly like any other invoice. Its
 * natural key is deterministic, which makes a re-run of the same month a replay
 * rather than a second parent.
 */
class ConsolidateIssuerMonth
{
    public const SOURCE_SYSTEM = 'engine-consolidation';

    /** LHDN reports consolidated receipts as a single unit of gross takings, tax-not-applicable. */
    private const LINE_UNIT_CODE = 'C62';

    private const LINE_TAX_TYPE = '06';

    private const DESCRIPTION_MAX = 300;

    public function __construct(
        private readonly CreateDocument $create,
        private readonly DocumentStateMachine $stateMachine,
    ) {}

    /** @return list<Document> the parent invoice for each currency, empty when the issuer has nothing to consolidate */
    public function handle(Issuer $issuer, CarbonImmutable $monthStart): array
    {
        $month = $monthStart->startOfMonth();

        $children = Document::query()
            ->where('issuer_id', $issuer->id)
            ->where('environment', $issuer->environment)
            ->where('status', DocumentStatus::AwaitingConsolidation)
            ->whereBetween('issue_date', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->with('lines')
            ->orderBy('source_ref')
            ->orderBy('id')
            ->get();

        $parents = [];
        foreach ($children->groupBy('currency')->sortKeys() as $currency => $group) {
            /** @var list<Document> $inCurrency */
            $inCurrency = $group->values()->all();
            $parents[] = $this->consolidate($issuer, $month, (string) $currency, $inCurrency);
        }

        return $parents;
    }

    /** @param list<Document> $children */
    private function consolidate(Issuer $issuer, CarbonImmutable $month, string $currency, array $children): Document
    {
        $data = CreateDocumentData::from([
            'type' => 'invoice',
            'issuer_id' => $issuer->id,
            'buyer' => ['general_public' => true],
            'currency' => $currency,
            'exchange_rate' => $this->exchangeRate($children),
            // Business date of the consolidation run itself, not of the receipts it covers.
            'issue_date' => CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString(),
            'lines' => $this->lines($children),
            'source' => ['system' => self::SOURCE_SYSTEM, 'ref' => $this->sourceRef($issuer, $month, $currency)],
            'consolidate' => false,
            'submit' => true,
            'metadata' => ['consolidation' => ['month' => $month->format('Y-m'), 'children' => count($children)]],
        ]);

        $parent = $this->create->handle($data)->document;

        // The natural key is fixed, so a parent LHDN rejected is replayed, never
        // replaced. Queue it for another run before re-linking the receipts its
        // failure released, or they would be marked consolidated into an invoice
        // that will never be reported.
        if ($parent->status === DocumentStatus::Invalid) {
            $this->stateMachine->transition($parent, DocumentStatus::Queued, 'consolidation_retry');
        }

        foreach ($children as $child) {
            // Linked before the transition so the document.consolidated webhook already carries the parent.
            $child->forceFill(['consolidated_into_id' => $parent->id])->save();
            $this->stateMachine->transition($child, DocumentStatus::Consolidated, 'consolidated');
        }

        return $parent;
    }

    private function sourceRef(Issuer $issuer, CarbonImmutable $month, string $currency): string
    {
        return "cons-{$issuer->id}-{$month->format('Y-m')}-{$currency}";
    }

    /**
     * One line per classification code. A child's money is attributed through its
     * own line totals, which sum exactly to its `total_payable` — so an ordinary
     * single-classification receipt puts its whole payable on that one code, and a
     * mixed receipt is split across the codes it actually used. Discounts and taxes
     * the children already applied are inside those totals; the consolidated line
     * reports gross takings and carries tax type 06.
     *
     * @param  list<Document>  $children
     * @return list<array<string, mixed>>
     */
    private function lines(array $children): array
    {
        /** @var array<string, array{amount: BigDecimal, refs: list<string>}> $groups */
        $groups = [];
        foreach ($children as $child) {
            foreach ($child->lines as $line) {
                $code = $line->classification_code;
                $groups[$code] ??= ['amount' => BigDecimal::zero(), 'refs' => []];
                $groups[$code]['amount'] = $groups[$code]['amount']->plus(Money::of($line->total));
                $groups[$code]['refs'][] = $child->source_ref;
            }
        }
        ksort($groups);

        $lines = [];
        foreach ($groups as $code => $group) {
            $refs = array_values(array_unique($group['refs']));
            sort($refs);
            $count = count($refs);
            $lines[] = [
                'classification_code' => $code,
                'description' => mb_substr("Receipts {$refs[0]} to {$refs[$count - 1]} ({$count} receipts)", 0, self::DESCRIPTION_MAX),
                'quantity' => 1,
                'unit_code' => self::LINE_UNIT_CODE,
                'unit_price' => Money::str($group['amount']),
                'tax_type' => self::LINE_TAX_TYPE,
            ];
        }

        return $lines;
    }

    /**
     * A foreign-currency parent needs a rate of its own. The receipts are ordered,
     * so taking the last one that carried a rate is deterministic across replays.
     *
     * @param  list<Document>  $children
     */
    private function exchangeRate(array $children): ?string
    {
        $rate = null;
        foreach ($children as $child) {
            $rate = $child->exchange_rate ?? $rate;
        }

        return $rate;
    }
}
