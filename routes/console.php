<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for the LHDN submission pipeline; the event-driven chain is the fast path.
Schedule::command('einvoice:lhdn-dispatch')->everyMinute()->withoutOverlapping();
