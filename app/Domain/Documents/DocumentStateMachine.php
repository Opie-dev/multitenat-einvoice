<?php

namespace App\Domain\Documents;

use App\Enums\DocumentStatus;
use App\Enums\HeldReason;
use App\Events\DocumentTransitioned;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DocumentStateMachine
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'draft' => ['validated'],
        'validated' => ['queued', 'held', 'awaiting_consolidation'],
        'held' => ['queued', 'held'],
        'queued' => ['submitted', 'held', 'invalid'],
        'submitted' => ['valid', 'invalid'],
        'invalid' => ['queued'],
        'valid' => ['cancelled', 'rejected'],
        'awaiting_consolidation' => ['consolidated', 'queued'],
        'cancelled' => [],
        'rejected' => [],
        'consolidated' => [],
    ];

    public function canTransition(DocumentStatus $from, DocumentStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }

    /** @param array<string, mixed> $meta */
    public function transition(Document $document, DocumentStatus $to, ?string $reason = null, array $meta = [], ?HeldReason $heldReason = null): DocumentEvent
    {
        if ($to === DocumentStatus::Held && $heldReason === null) {
            throw new InvalidArgumentException('A HeldReason is required when transitioning to held.');
        }
        $reason ??= $heldReason?->value;

        $from = $document->status;
        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransition($from, $to);
        }
        if ($to === DocumentStatus::Cancelled && ! $document->isCancellable()) {
            throw new CancellationWindowClosed;
        }

        return DB::transaction(function () use ($document, $from, $to, $reason, $meta, $heldReason): DocumentEvent {
            $now = now();
            $document->status = $to;
            $document->held_reason = $to === DocumentStatus::Held ? $heldReason : null;

            match ($to) {
                DocumentStatus::Validated => $document->validated_at = $now,
                DocumentStatus::Submitted => $document->submitted_at = $now,
                DocumentStatus::Valid, DocumentStatus::Invalid => $document->lhdn_status_at = $now,
                default => null,
            };
            if ($to === DocumentStatus::Cancelled) {
                $document->cancelled_at = $now;
                $document->cancel_reason = $reason;
            }

            $document->save();

            $actor = app(TenantContext::class)->actor();
            $event = $document->events()->create([
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason !== null ? mb_substr($reason, 0, 64) : null,
                'meta' => $meta === [] ? null : $meta,
                'actor_type' => $actor?->type,
                'actor_id' => $actor?->id,
                'created_at' => $now,
            ]);

            DocumentTransitioned::dispatch($document, $from, $to, $reason);

            return $event;
        });
    }
}
