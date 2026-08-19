<?php

namespace App\Lhdn;

use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Refuses to boot production with the in-memory LHDN double.
 *
 * `LHDN_DRIVER=fake` makes every submission succeed without ever reaching
 * MyInvois, so a stray value in a production .env would quietly report
 * thousands of invoices as `valid` that LHDN has never seen. Failing at boot
 * is the only safe outcome.
 */
final class LhdnDriverGuard
{
    public static function check(Application $app): void
    {
        if ($app->isProduction() && config('lhdn.driver') === 'fake') {
            throw new RuntimeException('LHDN_DRIVER=fake is not allowed in production');
        }
    }
}
