<?php

namespace App\Listeners;

use App\Events\IssuerActivated;
use App\Jobs\ReleaseHeldDocuments;

class ReleaseHeldDocumentsOnActivation
{
    public function handle(IssuerActivated $event): void
    {
        ReleaseHeldDocuments::dispatch($event->issuer->id);
    }
}
