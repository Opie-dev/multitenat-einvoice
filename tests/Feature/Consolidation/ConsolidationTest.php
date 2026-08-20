<?php

use App\Actions\Consolidation\ConsolidateIssuerMonth;
use App\Actions\Documents\CreateDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\Environment;
use App\Enums\WebhookEvent;
use App\Jobs\PrepareDocument;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/** A B2C receipt parked for consolidation, dated inside the month under test. */
function consolidationChild(Issuer $issuer, string $ref, string $classificationCode, string $unitPrice, string $issueDate, string $currency = 'MYR'): Document
{
    $payload = [
        'type' => 'invoice',
        'issuer_id' => $issuer->id,
        'buyer' => ['general_public' => true],
        'consolidate' => true,
        'currency' => $currency,
        'lines' => [[
            'classification_code' => $classificationCode,
            'description' => 'Receipt',
            'quantity' => 1,
            'unit_code' => 'C62',
            'unit_price' => $unitPrice,
            'tax_type' => '06',
        ]],
        'source' => ['system' => 'pos', 'ref' => $ref],
    ];
    if ($currency !== 'MYR') {
        $payload['exchange_rate'] = '4.5';
    }

    $document = app(CreateDocument::class)->handle(CreateDocumentData::from($payload))->document;
    $document->forceFill(['issue_date' => $issueDate])->save();

    return $document->refresh();
}

function consolidatedParent(string $currency): Document
{
    return Document::query()
        ->where('source_system', ConsolidateIssuerMonth::SOURCE_SYSTEM)
        ->where('currency', $currency)
        ->sole();
}

/** @return EloquentCollection<int, Document> */
function consolidatedParents(string $currency): EloquentCollection
{
    return Document::query()
        ->where('source_system', ConsolidateIssuerMonth::SOURCE_SYSTEM)
        ->where('currency', $currency)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();
}

beforeEach(function () {
    $certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));

    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create(['consolidation_enabled' => true]);
    $this->issuer->secret()->create([
        'signing_certificate' => $certs('test-cert.pem'),
        'signing_key' => $certs('test-key.pem'),
        'cert_not_after' => now()->addYears(5),
    ]);
});

it('consolidates a month into one invoice per currency, with one line per classification code', function () {
    // MYR: two classification codes, two receipts each.
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    consolidationChild($this->issuer, 'pos-002', '022', '20.50', '2026-07-14');
    consolidationChild($this->issuer, 'pos-003', '004', '5.25', '2026-07-20');
    consolidationChild($this->issuer, 'pos-004', '004', '4.75', '2026-07-31');
    // A second currency never shares a parent with the first.
    consolidationChild($this->issuer, 'pos-101', '022', '7.00', '2026-07-10', 'USD');
    // Neighbouring months stay put.
    $before = consolidationChild($this->issuer, 'pos-000', '022', '99.00', '2026-06-30');
    $after = consolidationChild($this->issuer, 'pos-999', '022', '88.00', '2026-08-01');

    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    // The caller's context survives the tenant sweep.
    expect(app(TenantContext::class)->tenant()->id)->toBe($this->tenant->id);

    $myr = consolidatedParent('MYR');
    expect($myr->status)->toBe(DocumentStatus::Valid) // through the real pipeline, fake LHDN
        ->and($myr->type->value)->toBe('invoice')
        ->and($myr->issuer_id)->toBe($this->issuer->id)
        ->and($myr->consolidate)->toBeFalse()
        ->and($myr->buyer_snapshot['general_public'])->toBeTrue()
        ->and($myr->source_ref)->toBe("cons-{$this->issuer->id}-2026-07-MYR")
        ->and($myr->issue_date->toDateString())->toBe(now('Asia/Kuala_Lumpur')->toDateString())
        ->and($myr->total_payable)->toBe('40.50')
        ->and($myr->metadata)->toBe(['consolidation' => ['month' => '2026-07', 'children' => 4]]);

    $lines = $myr->lines()->get();
    expect($lines)->toHaveCount(2);
    expect($lines[0]->classification_code)->toBe('004')
        ->and($lines[0]->quantity)->toBe('1.0000')
        ->and($lines[0]->unit_code)->toBe('C62')
        ->and($lines[0]->tax_type)->toBe('06')
        ->and($lines[0]->unit_price)->toBe('10.0000')
        ->and($lines[0]->total)->toBe('10.00')
        ->and($lines[0]->description)->toBe('Receipts pos-003 to pos-004 (2 receipts)');
    expect($lines[1]->classification_code)->toBe('022')
        ->and($lines[1]->unit_price)->toBe('30.5000')
        ->and($lines[1]->description)->toBe('Receipts pos-001 to pos-002 (2 receipts)');

    $usd = consolidatedParent('USD');
    expect($usd->total_payable)->toBe('7.00')
        ->and($usd->source_ref)->toBe("cons-{$this->issuer->id}-2026-07-USD")
        ->and($usd->metadata['consolidation']['children'])->toBe(1)
        ->and($usd->lines()->get()[0]->description)->toBe('Receipts pos-101 to pos-101 (1 receipts)');

    foreach (['pos-001', 'pos-002', 'pos-003', 'pos-004'] as $ref) {
        $child = Document::query()->where('source_ref', $ref)->sole();
        expect($child->status)->toBe(DocumentStatus::Consolidated)
            ->and($child->consolidated_into_id)->toBe($myr->id)
            ->and($child->events()->get()->last()->reason)->toBe('consolidated');
    }
    expect(Document::query()->where('source_ref', 'pos-101')->sole()->consolidated_into_id)->toBe($usd->id);

    expect($before->refresh()->status)->toBe(DocumentStatus::AwaitingConsolidation)
        ->and($before->consolidated_into_id)->toBeNull()
        ->and($after->refresh()->status)->toBe(DocumentStatus::AwaitingConsolidation);
});

it('is a replay on re-run: no second parent, no double-linking', function () {
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    consolidationChild($this->issuer, 'pos-002', '022', '20.50', '2026-07-14');

    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);
    $parent = consolidatedParent('MYR');
    $countAfterFirstRun = Document::query()->count();

    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    expect(Document::query()->count())->toBe($countAfterFirstRun)
        ->and(Document::query()->where('source_system', ConsolidateIssuerMonth::SOURCE_SYSTEM)->count())->toBe(1)
        ->and(consolidatedParent('MYR')->id)->toBe($parent->id)
        // Nothing was eligible, so the run reports nothing — not the parent's stored count.
        ->and(Artisan::output())->toContain('Consolidated 0 document(s) into 0 invoice(s) for 2026-07.');
});

it('replays onto the same parent when a previous run linked no children', function () {
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    consolidationChild($this->issuer, 'pos-002', '022', '20.50', '2026-07-14');

    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);
    $parent = consolidatedParent('MYR');

    // Simulate a run that created the parent but died before linking its children.
    Document::query()->where('source_system', 'pos')->get()->each(
        fn (Document $child) => $child->forceFill(['status' => DocumentStatus::AwaitingConsolidation, 'consolidated_into_id' => null])->save()
    );

    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    expect(Document::query()->where('source_system', ConsolidateIssuerMonth::SOURCE_SYSTEM)->count())->toBe(1)
        ->and(Artisan::output())->toContain('Consolidated 2 document(s) into 1 invoice(s) for 2026-07.');
    foreach (Document::query()->where('source_system', 'pos')->get() as $child) {
        expect($child->status)->toBe(DocumentStatus::Consolidated)
            ->and($child->consolidated_into_id)->toBe($parent->id);
    }
});

it('leaves issuers without consolidation enabled alone', function () {
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    $this->issuer->forceFill(['consolidation_enabled' => false])->save();

    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    expect(Document::query()->where('source_system', ConsolidateIssuerMonth::SOURCE_SYSTEM)->count())->toBe(0)
        ->and(Document::query()->where('source_ref', 'pos-001')->sole()->status)->toBe(DocumentStatus::AwaitingConsolidation);
});

it('defaults to the previous month when no --month is given', function () {
    $lastMonth = now('Asia/Kuala_Lumpur')->subMonthNoOverflow()->startOfMonth();
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', $lastMonth->copy()->addDays(3)->toDateString());
    consolidationChild($this->issuer, 'pos-002', '022', '5.00', now('Asia/Kuala_Lumpur')->toDateString());

    Artisan::call('einvoice:consolidate');

    $parent = consolidatedParent('MYR');
    expect($parent->source_ref)->toBe("cons-{$this->issuer->id}-{$lastMonth->format('Y-m')}-MYR")
        ->and($parent->total_payable)->toBe('10.00')
        ->and(Document::query()->where('source_ref', 'pos-002')->sole()->status)->toBe(DocumentStatus::AwaitingConsolidation);
});

it('rejects a malformed --month', function () {
    expect(Artisan::call('einvoice:consolidate', ['--month' => 'July-2026']))->toBe(1);
    expect(Document::query()->where('source_system', ConsolidateIssuerMonth::SOURCE_SYSTEM)->count())->toBe(0);
});

it('returns children to awaiting_consolidation and fires a webhook when the parent goes invalid', function () {
    Http::fake(['https://hooks.example.test/*' => Http::response(['ok' => true], 200)]);
    $endpoint = WebhookEndpoint::factory()->for($this->tenant)->create([
        'environment' => Environment::Sandbox,
        'url' => 'https://hooks.example.test/hook',
        'events' => [WebhookEvent::DocumentConsolidationFailed->value],
    ]);

    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    consolidationChild($this->issuer, 'pos-002', '022', '20.50', '2026-07-14');

    // Halt the parent at `queued` so we can drive the rejection ourselves.
    Queue::fake([PrepareDocument::class]);
    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    $parent = consolidatedParent('MYR');
    expect($parent->status)->toBe(DocumentStatus::Queued);

    app(DocumentStateMachine::class)->transition($parent, DocumentStatus::Invalid, 'rejected_at_submission');

    $children = Document::query()->where('source_system', 'pos')->orderBy('source_ref')->get();
    foreach ($children as $child) {
        expect($child->status)->toBe(DocumentStatus::AwaitingConsolidation)
            ->and($child->consolidated_into_id)->toBe($parent->id) // kept for audit; the next run overwrites it
            ->and($child->events()->get()->last()->reason)->toBe('consolidation_failed');
    }

    $deliveries = WebhookDelivery::withoutGlobalScopes()
        ->where('webhook_endpoint_id', $endpoint->id)
        ->where('event', WebhookEvent::DocumentConsolidationFailed->value)
        ->get();
    expect($deliveries)->toHaveCount(2);
    expect($deliveries->pluck('payload.data.source.ref')->sort()->values()->all())->toBe(['pos-001', 'pos-002']);
    expect($deliveries->first()->payload['data']['status'])->toBe('awaiting_consolidation');
});

it('supersedes a rejected parent with a new generation instead of replaying onto it', function () {
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    $base = "cons-{$this->issuer->id}-2026-07-MYR";

    Queue::fake([PrepareDocument::class]);
    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);
    $first = consolidatedParent('MYR');
    expect($first->source_ref)->toBe($base);

    app(DocumentStateMachine::class)->transition($first, DocumentStatus::Invalid, 'rejected_at_submission');
    expect(Document::query()->where('source_ref', 'pos-001')->sole()->status)->toBe(DocumentStatus::AwaitingConsolidation);

    $this->travel(1)->days();
    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    $second = consolidatedParents('MYR')->last();
    expect(consolidatedParents('MYR'))->toHaveCount(2);
    expect($second->source_ref)->toBe("{$base}-r2")
        ->and($second->status)->toBe(DocumentStatus::Queued)
        ->and($second->issue_date->toDateString())->toBe(now('Asia/Kuala_Lumpur')->toDateString())
        ->and($first->refresh()->status)->toBe(DocumentStatus::Invalid); // superseded, never resubmitted

    $child = Document::query()->where('source_ref', 'pos-001')->sole();
    expect($child->status)->toBe(DocumentStatus::Consolidated)
        ->and($child->consolidated_into_id)->toBe($second->id);

    // Generations count numerically, not lexicographically.
    app(DocumentStateMachine::class)->transition($second, DocumentStatus::Invalid, 'rejected_at_submission');
    $this->travel(1)->days();
    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);
    expect(consolidatedParents('MYR')->last()->source_ref)->toBe("{$base}-r3");
});

it('alarms and exits non-zero when an issuer cannot be consolidated', function () {
    Log::spy();
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    Queue::fake([PrepareDocument::class]);
    expect(Artisan::call('einvoice:consolidate', ['--month' => '2026-07']))->toBe(0);

    // A receipt arriving after a (non-invalid) parent was built changes the payload
    // behind an unchanged natural key, which CreateDocument refuses outright.
    Document::query()->where('source_ref', 'pos-001')->sole()
        ->forceFill(['status' => DocumentStatus::AwaitingConsolidation, 'consolidated_into_id' => null])->save();
    consolidationChild($this->issuer, 'pos-002', '022', '5.00', '2026-07-03');

    expect(Artisan::call('einvoice:consolidate', ['--month' => '2026-07']))->toBe(1);

    // report() logs too, so the matcher has to tolerate other error calls.
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context = []): bool => $message === 'consolidation.skipped'
        && ($context['issuer_id'] ?? null) === $this->issuer->id
        && ($context['month'] ?? null) === '2026-07'
        && ($context['currency'] ?? null) === 'MYR'
        && str_contains((string) ($context['exception'] ?? ''), 'different payload'));
});

it('never pools anything but invoices', function () {
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    // A note can no longer be created with consolidate => true, but one parked here
    // by older data must still never become a positive line on a consolidated invoice.
    $note = Document::factory()->for($this->issuer)->create([
        'type' => DocumentType::CreditNote,
        'status' => DocumentStatus::AwaitingConsolidation,
        'issue_date' => '2026-07-05',
        'source_system' => 'pos',
        'source_ref' => 'pos-cn-1',
        'total_payable' => '4.00',
    ]);

    Queue::fake([PrepareDocument::class]);
    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    expect($note->refresh()->status)->toBe(DocumentStatus::AwaitingConsolidation)
        ->and($note->consolidated_into_id)->toBeNull()
        ->and(consolidatedParent('MYR')->metadata['consolidation']['children'])->toBe(1);
});

it('ignores an invalid document that is not a consolidated parent', function () {
    consolidationChild($this->issuer, 'pos-001', '022', '10.00', '2026-07-02');
    Queue::fake([PrepareDocument::class]);
    Artisan::call('einvoice:consolidate', ['--month' => '2026-07']);

    // Only a consolidated parent can fail consolidation: a document from any other
    // source system must never release receipts, whatever points at it.
    $unrelated = Document::factory()->for($this->issuer)->queued()->create(['source_system' => 'pos']);
    Document::query()->where('source_ref', 'pos-001')->sole()
        ->forceFill(['consolidated_into_id' => $unrelated->id])->save();

    app(DocumentStateMachine::class)->transition($unrelated, DocumentStatus::Invalid, 'rejected_at_submission');

    expect(Document::query()->where('source_ref', 'pos-001')->sole()->status)->toBe(DocumentStatus::Consolidated);
});
