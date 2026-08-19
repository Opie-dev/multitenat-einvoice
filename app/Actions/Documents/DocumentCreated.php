<?php

namespace App\Actions\Documents;

use App\Models\Document;

/** Result of a create attempt: the document plus whether it was an idempotent replay. */
final class DocumentCreated
{
    public function __construct(public readonly Document $document, public readonly bool $replayed) {}
}
