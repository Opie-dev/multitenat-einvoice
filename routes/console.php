<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Safety net for the LHDN submission pipeline; the event-driven chain is the fast path.
Schedule::command('einvoice:lhdn-dispatch')->everyMinute()->withoutOverlapping();

// spec 5.6: the previous month's B2C receipts, consolidated well before the 7th.
Schedule::command('einvoice:consolidate')->dailyAt('01:00')->timezone('Asia/Kuala_Lumpur')->withoutOverlapping();
