<?php

namespace App\Data\Resources;

use App\Models\DocumentEvent;
use Spatie\LaravelData\Data;

class DocumentEventData extends Data
{
    /** @param array<string, mixed>|null $meta */
    public function __construct(
        public string $id,
        public ?string $from_status,
        public string $to_status,
        public ?string $reason,
        public ?array $meta,
        public ?string $actor_type,
        public ?string $actor_id,
        public string $created_at,
    ) {}

    public static function fromModel(DocumentEvent $e): self
    {
        return new self($e->id, $e->from_status?->value, $e->to_status->value, $e->reason, $e->meta, $e->actor_type, $e->actor_id, $e->created_at->toIso8601String());
    }
}
