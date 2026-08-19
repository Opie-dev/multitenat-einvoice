<?php

use App\Lhdn\LhdnDriverGuard;

it('refuses to boot production with the fake LHDN driver', function () {
    $this->app['env'] = 'production';
    config(['lhdn.driver' => 'fake']);

    expect(fn () => LhdnDriverGuard::check($this->app))
        ->toThrow(RuntimeException::class, 'LHDN_DRIVER=fake is not allowed in production');
});

it('allows the http driver in production and the fake driver everywhere else', function () {
    $this->app['env'] = 'production';
    config(['lhdn.driver' => 'http']);
    LhdnDriverGuard::check($this->app);

    $this->app['env'] = 'testing';
    config(['lhdn.driver' => 'fake']);
    LhdnDriverGuard::check($this->app);
})->throwsNoExceptions();
