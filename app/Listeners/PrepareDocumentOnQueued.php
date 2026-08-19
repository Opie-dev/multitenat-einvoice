<?php

namespace App\Listeners;

use App\Enums\DocumentStatus;
use App\Events\DocumentTransitioned;
use App\Jobs\PrepareDocument;

/** Entry point of the submission pipeline: anything that reaches `queued` gets prepared. */
class PrepareDocumentOnQueued
{
    public function handle(DocumentTransitioned $event): void
    {
        if ($event->to === DocumentStatus::Queued) {
            PrepareDocument::dispatch($event->document->id);
        }
    }
}
