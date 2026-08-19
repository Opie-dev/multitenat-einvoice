<?php

namespace App\Http\Middleware;

use App\Exceptions\ProblemException;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyKey
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return $next($request);
        }
        if (strlen($key) > 128) {
            throw ProblemException::badRequest('Idempotency-Key must be 1–128 characters.', 'invalid_idempotency_key');
        }
        $tenantId = $this->context->tenant()->getKey();
        $cacheKey = 'idem:'.$tenantId.':'.hash('sha256', $key);
        $requestHash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        /** @var array{status:int, content_type:?string, body:string, request_hash:string}|null $cached */
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            if ($cached['request_hash'] !== $requestHash) {
                throw ProblemException::conflict('This Idempotency-Key was already used with a different request.', 'idempotency_key_reused');
            }

            return response($cached['body'], $cached['status'], array_filter([
                'Content-Type' => $cached['content_type'],
                'Idempotent-Replay' => 'true',
            ]));
        }

        $response = $next($request);
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'content_type' => $response->headers->get('Content-Type'),
                'body' => (string) $response->getContent(),
                'request_hash' => $requestHash,
            ], now()->addHours((int) config('einvoice.idempotency_ttl_hours', 24)));
        }

        return $response;
    }
}
