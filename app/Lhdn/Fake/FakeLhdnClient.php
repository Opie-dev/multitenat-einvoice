<?php

namespace App\Lhdn\Fake;

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Data\DocumentDetails;
use App\Lhdn\Data\DocumentSummary;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionResult;
use App\Lhdn\Data\SubmissionStatus;
use App\Lhdn\LhdnClient;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * In-memory LHDN double for tests. Scriptable per operation; records every call.
 */
class FakeLhdnClient implements LhdnClient
{
    /** @var list<array{operation: string, issuer_id: ?string, args: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array{code: string, message: string}> */
    private array $rejections = [];

    /** @var array<string, array{internalId: string, status: string, longId: ?string, errors: list<array{code: string, message: string}>}> */
    private array $documents = [];

    /** @var array<string, list<string>> submissionUid => uuids */
    private array $submissions = [];

    /** @var array<string, int> */
    private array $pollCounts = [];

    private int $pollsUntilFinal = 0;

    private int $submissionCounter = 0;

    /** @var list<string> */
    private array $invalidTins = [];

    /** @var list<array{exception: LhdnException, operation: ?string}> */
    private array $failures = [];

    public function token(Issuer $issuer): AccessToken
    {
        $this->record('token', $issuer, []);
        $this->maybeFail('token');

        return new AccessToken('fake-token-'.$issuer->id, CarbonImmutable::now()->addHour());
    }

    public function submitDocuments(Issuer $issuer, SubmissionBatch $batch): SubmissionResult
    {
        $this->record('submit', $issuer, ['count' => $batch->count()]);
        $this->maybeFail('submit');
        $uid = 'SUB-'.(++$this->submissionCounter);
        $accepted = [];
        $rejected = [];
        foreach ($batch->documents as $doc) {
            if (isset($this->rejections[$doc->internalId])) {
                $rejected[$doc->internalId] = $this->rejections[$doc->internalId];

                continue;
            }
            $uuid = (string) Str::ulid();
            $accepted[$doc->internalId] = $uuid;
            $this->documents[$uuid] = ['internalId' => $doc->internalId, 'status' => 'Submitted', 'longId' => null, 'errors' => []];
            $this->submissions[$uid][] = $uuid;
        }

        return new SubmissionResult($uid, $accepted, $rejected);
    }

    public function getSubmission(Issuer $issuer, string $submissionUid): SubmissionStatus
    {
        $this->record('get_submission', $issuer, ['submission_uid' => $submissionUid]);
        $this->maybeFail('get_submission');
        $this->pollCounts[$submissionUid] = ($this->pollCounts[$submissionUid] ?? 0) + 1;
        $final = $this->pollCounts[$submissionUid] > $this->pollsUntilFinal;
        $summaries = [];
        $anyInvalid = false;
        $allValid = true;
        foreach ($this->submissions[$submissionUid] ?? [] as $uuid) {
            $d = $this->documents[$uuid];
            if ($final && $d['status'] === 'Submitted') {
                $d['status'] = 'Valid';
                $d['longId'] = 'L'.$uuid;
                $this->documents[$uuid] = $d;
            }
            $anyInvalid = $anyInvalid || $d['status'] === 'Invalid';
            $allValid = $allValid && $d['status'] === 'Valid';
            $summaries[] = new DocumentSummary($uuid, $d['internalId'], $d['longId'], $d['status'], $d['errors']);
        }
        $overall = ! $final ? 'in progress' : ($allValid ? 'valid' : ($anyInvalid && count($summaries) > 1 ? 'partially valid' : 'invalid'));

        return new SubmissionStatus($overall, $summaries);
    }

    public function getDocument(Issuer $issuer, string $uuid): DocumentDetails
    {
        $this->record('get_document', $issuer, ['uuid' => $uuid]);
        $this->maybeFail('get_document');
        $d = $this->documents[$uuid] ?? throw LhdnException::terminal("Unknown document {$uuid}", 404);

        return new DocumentDetails($uuid, $d['status'], $d['longId'], $d['errors']);
    }

    public function cancelDocument(Issuer $issuer, string $uuid, string $reason): void
    {
        $this->record('cancel', $issuer, ['uuid' => $uuid, 'reason' => $reason]);
        $this->maybeFail('cancel');
        if (isset($this->documents[$uuid])) {
            $this->documents[$uuid]['status'] = 'Cancelled';
        }
    }

    public function validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool
    {
        $this->record('validate_tin', $issuer, ['tin' => $tin, 'id_type' => $idType, 'id_value' => $idValue, 'environment' => $environment->value]);
        $this->maybeFail('validate_tin');

        return ! in_array($tin, $this->invalidTins, true);
    }

    // ---- scripting ----

    public function failNextWith(LhdnException $e, ?string $operation = null): void
    {
        $this->failures[] = ['exception' => $e, 'operation' => $operation];
    }

    public function pollsUntilFinal(int $n): void
    {
        $this->pollsUntilFinal = $n;
    }

    public function rejectDocument(string $internalId, string $code, string $message): void
    {
        $this->rejections[$internalId] = ['code' => $code, 'message' => $message];
    }

    /**
     * Scripting helper for tests that need a document to exist in the fake's
     * registry without going through submitDocuments() first — e.g. seeding a
     * document LHDN already knows about before a status-refresh call.
     *
     * @param  list<array{code: string, message: string}>  $errors
     */
    public function registerDocument(string $uuid, string $status, ?string $longId = null, array $errors = []): void
    {
        $this->documents[$uuid] = ['internalId' => '', 'status' => $status, 'longId' => $longId, 'errors' => $errors];
    }

    /** @param list<array{code: string, message: string}> $errors */
    public function markInvalid(string $uuid, array $errors): void
    {
        $document = $this->documents[$uuid] ?? ['internalId' => '', 'status' => 'Invalid', 'longId' => null, 'errors' => []];
        $document['status'] = 'Invalid';
        $document['errors'] = $errors;
        $this->documents[$uuid] = $document;
    }

    public function markRejected(string $uuid): void
    {
        $document = $this->documents[$uuid] ?? ['internalId' => '', 'status' => 'Rejected', 'longId' => null, 'errors' => []];
        $document['status'] = 'Rejected';
        $this->documents[$uuid] = $document;
    }

    public function markCancelled(string $uuid): void
    {
        $document = $this->documents[$uuid] ?? ['internalId' => '', 'status' => 'Cancelled', 'longId' => null, 'errors' => []];
        $document['status'] = 'Cancelled';
        $this->documents[$uuid] = $document;
    }

    public function invalidTin(string $tin): void
    {
        $this->invalidTins[] = $tin;
    }

    /** @return list<array{operation: string, issuer_id: ?string, args: array<string, mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->calls = $this->rejections = $this->documents = $this->submissions = $this->pollCounts = $this->invalidTins = $this->failures = [];
        $this->pollsUntilFinal = 0;
        $this->submissionCounter = 0;
    }

    /** @param array<string, mixed> $args */
    private function record(string $operation, ?Issuer $issuer, array $args): void
    {
        $this->calls[] = ['operation' => $operation, 'issuer_id' => $issuer?->id, 'args' => $args];
    }

    private function maybeFail(string $operation): void
    {
        $remaining = $this->failures;
        foreach ($remaining as $i => $f) {
            if ($f['operation'] === null || $f['operation'] === $operation) {
                unset($remaining[$i]);
                $this->failures = array_values($remaining);
                throw $f['exception'];
            }
        }
    }
}
