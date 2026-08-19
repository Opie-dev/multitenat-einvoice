<?php

namespace App\Events;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;

class DocumentTransitioned
{
    use Dispatchable;

    public function __construct(
        public readonly Document $document,
        public readonly ?DocumentStatus $from,
        public readonly DocumentStatus $to,
        public readonly ?string $reason,
    ) {}
}
