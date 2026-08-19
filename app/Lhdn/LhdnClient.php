<?php

namespace App\Lhdn;

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Data\DocumentDetails;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionResult;
use App\Lhdn\Data\SubmissionStatus;
use App\Models\Issuer;

interface LhdnClient
{
    public function token(Issuer $issuer): AccessToken;

    public function submitDocuments(Issuer $issuer, SubmissionBatch $batch): SubmissionResult;

    public function getSubmission(Issuer $issuer, string $submissionUid): SubmissionStatus;

    public function getDocument(Issuer $issuer, string $uuid): DocumentDetails;

    public function cancelDocument(Issuer $issuer, string $uuid, string $reason): void;

    public function validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool;
}
