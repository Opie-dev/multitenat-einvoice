<?php

namespace App\Lhdn\Http;

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Data\DocumentDetails;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionResult;
use App\Lhdn\Data\SubmissionStatus;
use App\Lhdn\LhdnClient;
use App\Lhdn\LhdnCredentials;
use App\Models\Issuer;
use LogicException;

/**
 * Placeholder for Plan 3 Task 3, which implements the real MyInvois HTTP client.
 */
class HttpLhdnClient implements LhdnClient
{
    public static function make(Environment $environment, LhdnCredentials $credentials): self
    {
        throw new LogicException('HttpLhdnClient is implemented in Plan 3 Task 3');
    }

    public function token(Issuer $issuer): AccessToken
    {
        throw new LogicException('HttpLhdnClient is implemented in Plan 3 Task 3');
    }

    public function submitDocuments(Issuer $issuer, SubmissionBatch $batch): SubmissionResult
    {
        throw new LogicException('HttpLhdnClient is implemented in Plan 3 Task 3');
    }

    public function getSubmission(Issuer $issuer, string $submissionUid): SubmissionStatus
    {
        throw new LogicException('HttpLhdnClient is implemented in Plan 3 Task 3');
    }

    public function getDocument(Issuer $issuer, string $uuid): DocumentDetails
    {
        throw new LogicException('HttpLhdnClient is implemented in Plan 3 Task 3');
    }

    public function cancelDocument(Issuer $issuer, string $uuid, string $reason): void
    {
        throw new LogicException('HttpLhdnClient is implemented in Plan 3 Task 3');
    }

    public function validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool
    {
        throw new LogicException('HttpLhdnClient is implemented in Plan 3 Task 3');
    }
}
