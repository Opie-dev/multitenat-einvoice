<?php

namespace App\Jobs;

use App\Domain\Documents\DocumentStateMachine;
use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Models\Document;
use App\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ReleaseHeldDocuments implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, TenantAwareJob;

    public function __construct(public readonly string $issuerId)
    {
        $this->captureTenantContext();
    }

    public function handle(DocumentStateMachine $stateMachine): void
    {
        $releasable = array_map(fn (HeldReason $r) => $r->value, array_filter(HeldReason::cases(), fn (HeldReason $r) => $r->releasableOnIssuerActivation()));

        Document::query()
            ->where('issuer_id', $this->issuerId)
            ->where('status', DocumentStatus::Held)
            ->whereIn('held_reason', $releasable)
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($stateMachine): void {
                foreach ($documents as $document) {
                    $stateMachine->transition($document, DocumentStatus::Queued, 'issuer_activated');
                }
            });
    }
}
