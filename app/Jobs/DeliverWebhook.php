<?php

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Sends one webhook delivery attempt and drives its retry curve.
 *
 * `tries` is 1 on purpose: retries are a business decision recorded on the
 * delivery row (`attempt` / `next_retry_at`) and re-driven by this job
 * re-dispatching itself with a delay — not by the queue worker, which would
 * otherwise replay the same attempt without advancing the backoff curve.
 * One job per delivery row, so no overlap middleware is needed.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId)
    {
        $this->captureTenantContext();
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::query()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        // Loaded via a direct, independently-nullable query rather than the
        // endpoint() relation: the row is guarded by a foreign key with
        // cascadeOnDelete(), but nothing in that constraint promises the model
        // layer a non-null result, so this must tolerate "gone" as well as "disabled".
        $endpoint = WebhookEndpoint::query()->find($delivery->webhook_endpoint_id);
        if ($endpoint === null || ! $endpoint->enabled) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Exhausted,
                'error_message' => 'endpoint_removed_or_disabled',
            ])->save();

            return;
        }

        // Sign the exact bytes that go over the wire: encoding $delivery->payload
        // again for the header (instead of reusing $body) could disagree with what
        // withBody() actually sends and produce a signature the receiver can't verify.
        $body = (string) json_encode($delivery->payload);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        try {
            $response = Http::withHeaders([
                'X-Einvoice-Event' => $delivery->event,
                'X-Einvoice-Signature' => $signature,
                'User-Agent' => 'billplz-einvoice/1.0',
            ])
                ->timeout((int) config('einvoice.webhooks.timeout', 10))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (ConnectionException $e) {
            $this->recordFailure($delivery, null, null, mb_substr($e->getMessage(), 0, 500));

            return;
        }

        if ($response->successful()) {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Delivered,
                'delivered_at' => now(),
                'http_status' => $response->status(),
                'attempt' => $delivery->attempt + 1,
            ])->save();

            return;
        }

        $this->recordFailure($delivery, $response->status(), mb_substr($response->body(), 0, 500), null);
    }

    /** Named away from InteractsWithQueue::fail(), which it would otherwise shadow. */
    private function recordFailure(WebhookDelivery $delivery, ?int $httpStatus, ?string $snippet, ?string $error): void
    {
        $attempt = $delivery->attempt + 1;
        $backoff = $this->backoffSeconds();

        $delivery->forceFill([
            'attempt' => $attempt,
            'http_status' => $httpStatus,
            'response_snippet' => $snippet,
            'error_message' => $error,
        ]);

        if ($attempt > count($backoff)) {
            $delivery->status = WebhookDeliveryStatus::Exhausted;
            // Nothing is scheduled any more; a leftover timestamp would read as a pending retry.
            $delivery->next_retry_at = null;
            $delivery->save();

            return;
        }

        $seconds = $backoff[$attempt - 1];
        $delivery->status = WebhookDeliveryStatus::Retrying;
        $delivery->next_retry_at = now()->addSeconds($seconds);
        $delivery->save();

        self::dispatch($delivery->id)->delay(now()->addSeconds($seconds));
    }

    /** @return non-empty-list<int> */
    private function backoffSeconds(): array
    {
        $configured = array_values(array_map(intval(...), (array) config('einvoice.webhooks.backoff_seconds', [60])));

        return $configured === [] ? [60] : $configured;
    }
}
