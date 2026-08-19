<?php

namespace App\Lhdn\Http;

use App\Enums\Environment;
use App\Lhdn\CircuitBreaker;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Data\DocumentDetails;
use App\Lhdn\Data\DocumentSummary;
use App\Lhdn\Data\SubmissionBatch;
use App\Lhdn\Data\SubmissionResult;
use App\Lhdn\Data\SubmissionStatus;
use App\Lhdn\LhdnClient;
use App\Lhdn\LhdnCredentials;
use App\Lhdn\LhdnErrorKind;
use App\Lhdn\LhdnException;
use App\Lhdn\LhdnRateLimiter;
use App\Models\Issuer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpLhdnClient implements LhdnClient
{
    public function __construct(
        private readonly Environment $environment,
        private readonly LhdnCredentials $credentials,
        private readonly TokenProvider $tokens,
        private readonly AttemptRecorder $attempts,
        private readonly CircuitBreaker $breaker,
        private readonly LhdnRateLimiter $limiter,
    ) {}

    public static function make(Environment $environment, LhdnCredentials $credentials): self
    {
        return new self($environment, $credentials, app(TokenProvider::class), app(AttemptRecorder::class), app(CircuitBreaker::class), app(LhdnRateLimiter::class));
    }

    public function token(Issuer $issuer): AccessToken
    {
        return $this->tokens->get($this->environment, $this->credentials, fn () => $this->fetchToken($issuer));
    }

    public function submitDocuments(Issuer $issuer, SubmissionBatch $batch): SubmissionResult
    {
        $summary = ['documents' => array_map(fn ($d) => ['codeNumber' => $d->internalId, 'documentHash' => $d->hashHex, 'bytes' => $d->sizeBytes()], $batch->documents)];
        $data = $this->call($issuer, 'submit', null, null, $summary, fn (PendingRequest $http) => $http->post('/api/v1.0/documentsubmissions', $batch->toPayload()));
        $accepted = [];
        foreach ((array) ($data['acceptedDocuments'] ?? []) as $a) {
            $accepted[(string) $a['invoiceCodeNumber']] = (string) $a['uuid'];
        }
        $rejected = [];
        foreach ((array) ($data['rejectedDocuments'] ?? []) as $r) {
            $rejected[(string) $r['invoiceCodeNumber']] = ['code' => (string) ($r['error']['code'] ?? 'rejected'), 'message' => (string) ($r['error']['message'] ?? 'Rejected by LHDN')];
        }

        return new SubmissionResult((string) ($data['submissionUid'] ?? ''), $accepted, $rejected);
    }

    public function getSubmission(Issuer $issuer, string $submissionUid): SubmissionStatus
    {
        $data = $this->call($issuer, 'get_submission', null, $submissionUid, ['submission_uid' => $submissionUid], fn (PendingRequest $http) => $http->get("/api/v1.0/documentsubmissions/{$submissionUid}", ['pageNo' => 1, 'pageSize' => 100]));
        $docs = [];
        foreach ((array) ($data['documentSummary'] ?? []) as $d) {
            $docs[] = new DocumentSummary((string) $d['uuid'], (string) ($d['internalId'] ?? ''), isset($d['longId']) && $d['longId'] !== '' ? (string) $d['longId'] : null, (string) ($d['status'] ?? 'Submitted'));
        }

        return new SubmissionStatus((string) ($data['overallStatus'] ?? 'in progress'), $docs);
    }

    public function getDocument(Issuer $issuer, string $uuid): DocumentDetails
    {
        $data = $this->call($issuer, 'get_document', null, null, ['uuid' => $uuid], fn (PendingRequest $http) => $http->get("/api/v1.0/documents/{$uuid}/details"));
        $errors = [];
        foreach ((array) data_get($data, 'validationResults.validationSteps', []) as $step) {
            if (strtolower((string) ($step['status'] ?? '')) === 'invalid') {
                $errors[] = array_filter([
                    'code' => (string) data_get($step, 'error.code', 'invalid'),
                    'message' => (string) data_get($step, 'error.message', (string) ($step['name'] ?? 'Validation failed')),
                    'target' => data_get($step, 'error.target'),
                ], fn ($v) => $v !== null);
            }
        }

        return new DocumentDetails((string) ($data['uuid'] ?? $uuid), (string) ($data['status'] ?? ''), isset($data['longId']) && $data['longId'] !== '' ? (string) $data['longId'] : null, $errors);
    }

    public function cancelDocument(Issuer $issuer, string $uuid, string $reason): void
    {
        $this->call($issuer, 'cancel', null, null, ['uuid' => $uuid, 'reason' => $reason], fn (PendingRequest $http) => $http->put("/api/v1.0/documents/state/{$uuid}/state", ['status' => 'cancelled', 'reason' => $reason]));
    }

    public function validateTin(Environment $environment, string $tin, string $idType, string $idValue, ?Issuer $issuer = null): bool
    {
        // A client is built for exactly one environment; asking it to validate against
        // the other one would silently use the wrong base URL and credentials.
        if ($environment !== $this->environment) {
            throw new \InvalidArgumentException("HttpLhdnClient is bound to {$this->environment->value}; it cannot validate a TIN in {$environment->value}.");
        }
        $issuerForRecord = $issuer ?? throw new \InvalidArgumentException('HttpLhdnClient::validateTin requires an issuer for attempt recording; use the intermediary client with a system issuer or pass the acting issuer.');
        try {
            $this->call($issuerForRecord, 'validate_tin', null, null, ['tin' => $tin, 'id_type' => $idType], fn (PendingRequest $http) => $http->get("/api/v1.0/taxpayer/validate/{$tin}", ['idType' => $idType, 'idValue' => $idValue]));

            return true;
        } catch (LhdnException $e) {
            if ($e->kind === LhdnErrorKind::Terminal && $e->httpStatus === 404) {
                return false;
            }
            throw $e;
        }
    }

    // ---- internals ----

    private function fetchToken(Issuer $issuer): AccessToken
    {
        $start = hrtime(true);
        $request = ['client_id' => substr($this->credentials->clientId, 0, 4).'***', 'scope' => 'InvoicingAPI', 'onbehalfof' => $this->credentials->onBehalfOf];
        // Mirrors call(): an open breaker or an exhausted local rate budget is our own
        // bookkeeping, not something LHDN said. Those must propagate untouched — no
        // submission_attempts row for a request that never left the process, and no
        // breaker failure for a rejection the breaker/limiter produced itself.
        $sent = false;
        try {
            $this->breaker->guard($this->environment);
            $response = $this->limiter->attempt($issuer, 'token', function () use (&$sent) {
                $sent = true;

                return $this->identity()->asForm()->post('/connect/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->credentials->clientId,
                    'client_secret' => $this->credentials->clientSecret,
                    'scope' => 'InvoicingAPI',
                ]);
            });
            $data = $this->classify($response);
            $token = new AccessToken((string) $data['access_token'], CarbonImmutable::now()->addSeconds((int) ($data['expires_in'] ?? 3600)));
            $this->attempts->record($issuer, $this->environment, 'token', null, null, $response->status(), $request, ['expires_in' => $data['expires_in'] ?? null], null, $this->ms($start));
            $this->breaker->recordSuccess($this->environment);

            return $token;
        } catch (ConnectionException $e) {
            $ex = LhdnException::transient('LHDN identity service unreachable: '.$e->getMessage());
            $this->attempts->record($issuer, $this->environment, 'token', null, null, null, $request, null, $ex, $this->ms($start));
            $this->breaker->recordFailure($this->environment);
            throw $ex;
        } catch (LhdnException $e) {
            if (! $sent) {
                throw $e;
            }
            $this->attempts->record($issuer, $this->environment, 'token', null, null, $e->httpStatus, $request, $e->payload, $e, $this->ms($start));
            if ($this->countsAsBreakerFailure($e)) {
                $this->breaker->recordFailure($this->environment);
            }
            throw $e;
        }
    }

    /**
     * Only a platform-level problem may open the breaker: a connection failure or a
     * 5xx. An HTTP 429 from MyInvois is a per-taxpayer rate limit, so counting it
     * would stop batching for every other issuer in the environment because one
     * issuer was noisy.
     */
    private function countsAsBreakerFailure(LhdnException $e): bool
    {
        return $e->kind === LhdnErrorKind::Transient && ($e->httpStatus ?? 500) >= 500;
    }

    /**
     * @param  array<string, mixed>|null  $requestSummary
     * @param  callable(PendingRequest): Response  $send
     * @return array<string, mixed>
     */
    private function call(Issuer $issuer, string $operation, ?string $documentId, ?string $submissionUid, ?array $requestSummary, callable $send): array
    {
        $start = hrtime(true);
        $this->breaker->guard($this->environment);
        // Tracks whether $this->token($issuer) below has already returned successfully. fetchToken() fully
        // records its own attempt + breaker outcome, so a failure that occurs before this flips true must
        // propagate untouched — recording it again here (mislabelled under $operation, since no HTTP call to
        // $operation's endpoint was ever made) would double-count both the SubmissionAttempt row and the
        // circuit breaker failure for what is really a single underlying failure.
        $tokenAcquired = false;
        try {
            $response = $this->limiter->attempt($issuer, $operation, function () use ($issuer, $send, &$tokenAcquired) {
                $token = $this->token($issuer);
                $tokenAcquired = true;

                return $send($this->api()->withToken($token->token));
            });
            $data = $this->classify($response);
            $uid = $submissionUid ?? (isset($data['submissionUid']) ? (string) $data['submissionUid'] : null);
            $this->attempts->record($issuer, $this->environment, $operation, $documentId, $uid, $response->status(), $requestSummary, $data, null, $this->ms($start));
            $this->breaker->recordSuccess($this->environment);

            return $data;
        } catch (ConnectionException $e) {
            if (! $tokenAcquired) {
                throw $e;
            }
            $ex = LhdnException::transient('LHDN API unreachable: '.$e->getMessage());
            $this->attempts->record($issuer, $this->environment, $operation, $documentId, $submissionUid, null, $requestSummary, null, $ex, $this->ms($start));
            $this->breaker->recordFailure($this->environment);
            throw $ex;
        } catch (LhdnException $e) {
            if (! $tokenAcquired) {
                throw $e;
            }
            $this->attempts->record($issuer, $this->environment, $operation, $documentId, $submissionUid, $e->httpStatus, $requestSummary, $e->payload, $e, $this->ms($start));
            if ($e->kind === LhdnErrorKind::Auth) {
                $this->tokens->forget($this->environment, $this->credentials);
            }
            if ($this->countsAsBreakerFailure($e)) {
                $this->breaker->recordFailure($this->environment);
            }
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function classify(Response $response): array
    {
        $status = $response->status();
        $body = $response->json();
        $payload = is_array($body) ? $body : ['raw' => mb_substr((string) $response->body(), 0, 2000)];
        if ($response->successful()) {
            return is_array($body) ? $body : [];
        }
        $rawMessage = data_get($payload, 'error.message') ?? data_get($payload, 'message') ?? data_get($payload, 'title') ?? data_get($payload, 'error') ?? "HTTP {$status}";
        // some LHDN errors nest arrays under "error" — json-encode those before stringifying
        $message = is_array($rawMessage) ? (string) json_encode($rawMessage) : (string) $rawMessage;
        throw match (true) {
            $status === 401 || $status === 403 => LhdnException::auth("LHDN rejected the credentials ({$status}): {$message}", $status, $payload),
            $status === 429 || $status >= 500 => LhdnException::transient("LHDN temporarily unavailable ({$status}): {$message}", $status, $payload),
            default => LhdnException::terminal("LHDN rejected the request ({$status}): {$message}", $status, $payload),
        };
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl((string) config("lhdn.environments.{$this->environment->value}.api_base"))->timeout((int) config('lhdn.timeout', 30))->acceptJson();
    }

    private function identity(): PendingRequest
    {
        $req = Http::baseUrl((string) config("lhdn.environments.{$this->environment->value}.identity_base"))->timeout((int) config('lhdn.timeout', 30))->acceptJson();

        return $this->credentials->onBehalfOf !== null ? $req->withHeaders(['onbehalfof' => $this->credentials->onBehalfOf]) : $req;
    }

    private function ms(int $startNs): int
    {
        return (int) ((hrtime(true) - $startNs) / 1_000_000);
    }
}
