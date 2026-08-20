<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

it('renders an inertia page with shared flash props', function () {
    Route::middleware('web')->get('/__inertia-test', fn () => Inertia::render('Errors/NotFound'));

    $response = $this->get('/__inertia-test');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Errors/NotFound')
        ->has('auth.user')
        ->has('tenant')
        ->has('environment')
        ->has('flash.success')
        ->has('flash.secret')
        ->where('auth.user', null)
        ->where('tenant', null)
        ->where('environment', null)
    );
});
