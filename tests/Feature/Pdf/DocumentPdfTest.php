<?php

use App\Actions\Documents\CreateDocument;
use App\Data\Requests\Documents\CreateDocumentData;
use App\Enums\Environment;
use App\Models\Document;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * A document reaching `valid` needs the whole submission pipeline (issuer with
 * signing cert, PrepareDocument -> SubmitDocuments -> PollSubmission on the sync
 * queue against the fake LHDN driver), not just a factory state, because the PDF
 * route gates on `lhdn_uuid` and the generator needs `lhdn_long_id` for the QR.
 */
function pdfPipelineDocument(Issuer $issuer): Document
{
    $payload = [
        'type' => 'invoice', 'issuer_id' => $issuer->id, 'buyer' => ['general_public' => true],
        'lines' => [['classification_code' => '022', 'description' => 'Widget', 'quantity' => 1, 'unit_code' => 'C62', 'unit_price' => '10.00', 'tax_type' => '02', 'tax_rate' => 6]],
        'source' => ['system' => 'test', 'ref' => 'ref-'.bin2hex(random_bytes(4))],
    ];

    return app(CreateDocument::class)->handle(CreateDocumentData::from($payload))->document->refresh();
}

beforeEach(function () {
    Storage::fake('local');
    $certs = fn (string $f) => (string) file_get_contents(base_path("tests/Fixtures/certs/{$f}"));
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->bind($this->tenant, null, Environment::Sandbox);
    $this->issuer = Issuer::factory()->for($this->tenant)->active()->create();
    $this->issuer->secret()->create([
        'signing_certificate' => $certs('test-cert.pem'),
        'signing_key' => $certs('test-key.pem'),
        'cert_not_after' => now()->addYears(5),
    ]);
});

it('renders and caches the PDF for a valid document', function () {
    $doc = pdfPipelineDocument($this->issuer);
    app(TenantContext::class)->clear();
    $h = apiKeyHeaders($this->tenant, 'sandbox');

    $response = $this->withHeaders($h)->get("/v1/documents/{$doc->id}/pdf");

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->streamedContent())->toStartWith('%PDF');

    $doc->refresh();
    expect($doc->pdf_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($doc->pdf_path))->toBeTrue();
});

it('does not regenerate the PDF on a second request', function () {
    $doc = pdfPipelineDocument($this->issuer);
    app(TenantContext::class)->clear();
    $h = apiKeyHeaders($this->tenant, 'sandbox');

    $this->withHeaders($h)->get("/v1/documents/{$doc->id}/pdf")->assertOk();
    $path = $doc->refresh()->pdf_path;
    $firstModified = Storage::disk('local')->lastModified($path);

    $this->withHeaders($h)->get("/v1/documents/{$doc->id}/pdf")->assertOk();
    $secondModified = Storage::disk('local')->lastModified($path);

    expect($secondModified)->toBe($firstModified);
});

it('regenerates the PDF once the document changes (e.g. cancellation)', function () {
    $doc = pdfPipelineDocument($this->issuer);
    app(TenantContext::class)->clear();
    $h = apiKeyHeaders($this->tenant, 'sandbox');

    $this->withHeaders($h)->get("/v1/documents/{$doc->id}/pdf")->assertOk();
    $path = $doc->refresh()->pdf_path;
    // Real filesystem mtimes only have 1-second resolution, so a fast test run can tie
    // with the (regenerated) second file. Backdate the first file so any real-time
    // regeneration afterwards is unambiguously newer, however fast the test runs.
    touch(Storage::disk('local')->path($path), time() - 120);
    $firstModified = Storage::disk('local')->lastModified($path);

    $doc->forceFill([
        'status' => 'cancelled',
        'cancel_reason' => 'x',
        'updated_at' => now()->addMinute(),
    ])->save();

    $this->withHeaders($h)->get("/v1/documents/{$doc->id}/pdf")->assertOk();
    $secondModified = Storage::disk('local')->lastModified($doc->refresh()->pdf_path);

    expect($secondModified)->toBeGreaterThan($firstModified);
});

it('returns 409 pdf_not_available for a document not yet validated by LHDN', function () {
    $doc = Document::factory()->for($this->issuer)->queued()->create(['environment' => Environment::Sandbox]);
    app(TenantContext::class)->clear();
    $h = apiKeyHeaders($this->tenant, 'sandbox');

    $this->withHeaders($h)->get("/v1/documents/{$doc->id}/pdf")
        ->assertStatus(409)->assertJsonPath('code', 'pdf_not_available');
});

it('requires the read ability', function () {
    $doc = pdfPipelineDocument($this->issuer);
    app(TenantContext::class)->clear();

    $h = apiKeyHeaders($this->tenant, 'sandbox', ['documents:write']);
    $this->withHeaders($h)->get("/v1/documents/{$doc->id}/pdf")->assertStatus(403);
});
