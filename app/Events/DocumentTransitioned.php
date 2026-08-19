<?php

namespace App\Events;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Dispatched only once the transition is durable; a no-op outside a transaction. */
class DocumentTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Document $document,
        public readonly ?DocumentStatus $from,
        public readonly DocumentStatus $to,
        public readonly ?string $reason,
    ) {}
}
