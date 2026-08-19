<?php

use App\Enums\Environment;
use App\Lhdn\CircuitBreaker;
use App\Lhdn\LhdnException;

it('opens after the failure threshold and closes after cooldown', function () {
    config(['lhdn.circuit_breaker' => ['failure_threshold' => 2, 'cooldown_seconds' => 60]]);
    $cb = new CircuitBreaker;
    $cb->recordFailure(Environment::Sandbox);
    expect($cb->isOpen(Environment::Sandbox))->toBeFalse();
    $cb->recordFailure(Environment::Sandbox);
    expect($cb->isOpen(Environment::Sandbox))->toBeTrue()->and($cb->isOpen(Environment::Production))->toBeFalse();
    expect(fn () => $cb->guard(Environment::Sandbox))->toThrow(LhdnException::class);
    $this->travel(61)->seconds();
    expect($cb->isOpen(Environment::Sandbox))->toBeFalse();
    $cb->recordFailure(Environment::Sandbox);
    $cb->recordSuccess(Environment::Sandbox);
    $cb->recordFailure(Environment::Sandbox);
    expect($cb->isOpen(Environment::Sandbox))->toBeFalse(); // success reset the streak
});
