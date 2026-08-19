<?php

namespace App\Lhdn\Http;

use App\Enums\Environment;
use App\Lhdn\LhdnException;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;

class AttemptRecorder
{
    /**
     * @param  array<string, mixed>|null  $request  already-redacted request summary
     * @param  array<string, mixed>|null  $response
     */
    public function record(Issuer $issuer, Environment $environment, string $operation, ?string $documentId, ?string $submissionUid, ?int $httpStatus, ?array $request, ?array $response, ?LhdnException $error, int $durationMs): SubmissionAttempt
    {
        return SubmissionAttempt::create([
            'issuer_id' => $issuer->id,
            'document_id' => $documentId,
            'submission_uid' => $submissionUid,
            'operation' => $operation,
            'environment' => $environment,
            'http_status' => $httpStatus,
            'request' => $request,
            'response' => $response === null ? null : self::truncate($response),
            'error_kind' => $error?->kind->value,
            'error_message' => $error ? mb_substr($error->getMessage(), 0, 500) : null,
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function truncate(array $response): array
    {
        $json = json_encode($response);
        if ($json !== false && strlen($json) > 65535) {
            return ['_truncated' => true, 'preview' => substr($json, 0, 65000)];
        }

        return $response;
    }
}
