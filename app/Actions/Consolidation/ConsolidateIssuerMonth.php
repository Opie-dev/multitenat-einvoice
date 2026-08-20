<?php

namespace App\Actions\Consolidation;

use App\Actions\Documents\CreateDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Domain\Documents\DocumentStateMachine;
use App\Domain\Documents\Money;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\Issuer;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Folds one issuer's month of B2C receipts into consolidated invoices — one per
 * currency, each with a line per classification code — and links the receipts to
 * the parent they were reported under.
 *
 * The parent is created through the ordinary CreateDocument path, so it is
 * validated, totalled, queued and submitted exactly like any other invoice. Its
 * natural key is generation-suffixed: the first attempt at a month is `{base}`,
 * and each parent LHDN rejects is superseded by `{base}-r{n+1}` rather than
 * replayed. A parent that has not been rejected keeps its key, so an unchanged
 * re-run of the same month is an idempotent replay.
 *
 * The month is walked lazily and linked in chunks: a busy issuer's month can hold
 * hundreds of thousands of receipts, and only the per-classification aggregates
 * (not the receipts) are held in memory.
 */
class ConsolidateIssuerMonth
{
    public const SOURCE_SYSTEM = 'engine-consolidation';

    /** LHDN reports consolidated receipts as a single unit of gross takings, tax-not-applicable. */
    private const LINE_UNIT_CODE = 'C62';

    private const LINE_TAX_TYPE = '06';

    private const DESCRIPTION_MAX = 300;

    private const CHUNK = 500;

    public function __construct(
        private readonly CreateDocument $create,
        private readonly DocumentStateMachine $stateMachine,
    ) {}

    public function handle(Issuer $issuer, CarbonImmutable $monthStart): ConsolidationOutcome
    {
        $month = $monthStart->startOfMonth();
        $groups = $this->accumulate($issuer, $month);

        $parents = [];
        $consolidated = 0;
        foreach ($groups as $currency => $group) {
            try {
                $parents[] = $this->consolidate($issuer, $month, (string) $currency, $group);
            } catch (Throwable $e) {
                throw new ConsolidationFailed($issuer->id, (string) $currency, $e);
            }
            $consolidated += count($group['ids']);
        }

        return new ConsolidationOutcome($parents, $consolidated);
    }

    /**
     * Streams the month once, folding each receipt into its currency group. Only
     * the receipt ids and the per-classification aggregates survive the walk —
     * never the documents or their lines.
     *
     * @return array<string, array{ids: list<string>, rate: string|null, codes: array<string, array{amount: BigDecimal, min: string, max: string, count: int}>}>
     */
    private function accumulate(Issuer $issuer, CarbonImmutable $month): array
    {
        $groups = [];

        foreach ($this->eligible($issuer, $month)->with('lines')->lazyById(self::CHUNK) as $child) {
            $group = $groups[$child->currency] ?? ['ids' => [], 'rate' => null, 'codes' => []];
            $group['ids'][] = $child->id;
            // Walked in id order, so the last receipt carrying a rate is the most
            // recently created one — the closest thing to the month's closing rate
            // the engine has, and stable across replays of the same receipt set.
            $group['rate'] = $child->exchange_rate ?? $group['rate'];

            $counted = [];
            foreach ($child->lines as $line) {
                $code = $line->classification_code;
                $entry = $group['codes'][$code]
                    ?? ['amount' => BigDecimal::zero(), 'min' => $child->source_ref, 'max' => $child->source_ref, 'count' => 0];

                $entry['amount'] = $entry['amount']->plus(Money::of($line->total));
                $entry['min'] = min($entry['min'], $child->source_ref);
                $entry['max'] = max($entry['max'], $child->source_ref);
                // A receipt splitting one classification over several lines is still one receipt.
                if (! isset($counted[$code])) {
                    $entry['count']++;
                    $counted[$code] = true;
                }

                $group['codes'][$code] = $entry;
            }

            $groups[$child->currency] = $group;
        }
        ksort($groups);

        return $groups;
    }

    /** @return Builder<Document> */
    private function eligible(Issuer $issuer, CarbonImmutable $month): Builder
    {
        return Document::query()
            ->where('issuer_id', $issuer->id)
            ->where('environment', $issuer->environment)
            // Notes net against an invoice; pooled as positive consolidated lines they
            // would overstate the month, so only invoices are ever consolidated.
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::AwaitingConsolidation)
            ->whereBetween('issue_date', [$month->toDateString(), $month->endOfMonth()->toDateString()]);
    }

    /** @param array{ids: list<string>, rate: string|null, codes: array<string, array{amount: BigDecimal, min: string, max: string, count: int}>} $group */
    private function consolidate(Issuer $issuer, CarbonImmutable $month, string $currency, array $group): Document
    {
        $data = CreateDocumentData::from([
            'type' => 'invoice',
            'issuer_id' => $issuer->id,
            'buyer' => ['general_public' => true],
            'currency' => $currency,
            'exchange_rate' => $group['rate'],
            // Business date of the consolidation run itself, not of the receipts it
            // covers. Safe to move between runs: a re-reported month is a new generation.
            'issue_date' => CarbonImmutable::now('Asia/Kuala_Lumpur')->toDateString(),
            'lines' => $this->lines($group['codes']),
            'source' => ['system' => self::SOURCE_SYSTEM, 'ref' => $this->sourceRef($issuer, $month, $currency)],
            'consolidate' => false,
            'submit' => true,
            'metadata' => ['consolidation' => ['month' => $month->format('Y-m'), 'children' => count($group['ids'])]],
        ]);

        $parent = $this->create->handle($data)->document;

        foreach (array_chunk($group['ids'], self::CHUNK) as $chunk) {
            foreach (Document::query()->whereIn('id', $chunk)->get() as $child) {
                // Linked before the transition so the document.consolidated webhook already carries the parent.
                $child->forceFill(['consolidated_into_id' => $parent->id])->save();
                $this->stateMachine->transition($child, DocumentStatus::Consolidated, 'consolidated');
            }
        }

        return $parent;
    }

    /**
     * `{base}` for the first attempt at a month, `{base}-r{n}` for every attempt
     * after a rejection. Resubmitting a rejected parent is pointless — the payload
     * would be byte-identical to the one LHDN refused — so the month is reported
     * again as a new document, picking up the receipts the release put back in the
     * pool plus anything that has arrived since.
     */
    private function sourceRef(Issuer $issuer, CarbonImmutable $month, string $currency): string
    {
        $base = "cons-{$issuer->id}-{$month->format('Y-m')}-{$currency}";

        // Currency codes are exactly three characters, so `{base}%` cannot reach
        // another currency's generations, and neither ULIDs nor currency codes
        // contain LIKE wildcards.
        $latest = Document::query()
            ->where('issuer_id', $issuer->id)
            ->where('environment', $issuer->environment)
            ->where('source_system', self::SOURCE_SYSTEM)
            ->where('source_ref', 'like', "{$base}%")
            ->get(['source_ref', 'status'])
            ->sortBy(fn (Document $parent): int => $this->generation($parent->source_ref, $base))
            ->last();

        if ($latest === null) {
            return $base;
        }

        return $latest->status === DocumentStatus::Invalid
            ? $base.'-r'.($this->generation($latest->source_ref, $base) + 1)
            : $latest->source_ref;
    }

    /** Generations are compared numerically; `-r10` follows `-r9`, not `-r1`. */
    private function generation(string $ref, string $base): int
    {
        if ($ref === $base) {
            return 1;
        }

        return preg_match('/^'.preg_quote($base, '/').'-r(\d+)$/', $ref, $matches) === 1 ? (int) $matches[1] : 0;
    }

    /**
     * One line per classification code, ordered by code. A child's money is
     * attributed through its own line totals, which sum exactly to its
     * `total_payable` — so an ordinary single-classification receipt puts its whole
     * payable on that one code, and a mixed receipt is split across the codes it
     * actually used. Discounts and taxes the children already applied are inside
     * those totals; the consolidated line reports gross takings, tax type 06.
     *
     * @param  array<string, array{amount: BigDecimal, min: string, max: string, count: int}>  $codes
     * @return list<array<string, mixed>>
     */
    private function lines(array $codes): array
    {
        ksort($codes);

        $lines = [];
        foreach ($codes as $code => $entry) {
            $lines[] = [
                'classification_code' => $code,
                // source_ref is varchar(191), so two long refs could overrun the column.
                'description' => mb_substr("Receipts {$entry['min']} to {$entry['max']} ({$entry['count']} receipts)", 0, self::DESCRIPTION_MAX),
                'quantity' => 1,
                'unit_code' => self::LINE_UNIT_CODE,
                'unit_price' => Money::str($entry['amount']),
                'tax_type' => self::LINE_TAX_TYPE,
            ];
        }

        return $lines;
    }
}
