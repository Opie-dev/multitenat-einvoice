<?php

use App\Enums\Environment;
use App\Lhdn\Data\AccessToken;
use App\Lhdn\Http\TokenProvider;
use App\Lhdn\LhdnCredentials;
use Carbon\CarbonImmutable;

it('caches tokens per environment + credentials and refreshes when near expiry', function () {
    $tp = new TokenProvider;
    $creds = new LhdnCredentials('id', 'secret', 'C123', 'intermediary');
    $calls = 0;
    $fetch = function () use (&$calls) {
        $calls++;

        return new AccessToken('t'.$calls, CarbonImmutable::now()->addSeconds(3600));
    };
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t1');
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t1');
    expect($tp->get(Environment::Production, $creds, $fetch)->token)->toBe('t2');
    $other = new LhdnCredentials('id', 'secret', 'C999', 'intermediary');
    expect($tp->get(Environment::Sandbox, $other, $fetch)->token)->toBe('t3');
    $tp->forget(Environment::Sandbox, $creds);
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t4');
    $this->travel(3550)->seconds(); // inside the 60s margin
    expect($tp->get(Environment::Sandbox, $creds, $fetch)->token)->toBe('t5');
});
