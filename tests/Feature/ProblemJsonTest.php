<?php

use App\Exceptions\ProblemException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Route::middleware('api')->prefix('v1')->group(function () {
        Route::get('/_test/problem', fn () => throw ProblemException::conflict('Already there', 'duplicate'));
        Route::get('/_test/validation', fn () => throw ValidationException::withMessages(['lines.0.qty' => ['must be > 0']]));
        Route::get('/_test/validation-rules', fn () => Validator::make(
            ['lines' => [['qty' => 0]]],
            ['lines.0.qty' => ['required', 'integer', 'min:1'], 'name' => ['required']]
        )->validate());
        Route::get('/_test/boom', fn () => throw new RuntimeException('secret detail'));
    });
});

it('renders ProblemException as problem+json', function () {
    $this->getJson('/v1/_test/problem')
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'type' => 'https://einvoice.billplz.com/problems/duplicate',
            'title' => 'Conflict',
            'status' => 409,
            'detail' => 'Already there',
            'code' => 'duplicate',
        ]);
});

it('renders validation errors with JSON pointers', function () {
    $this->getJson('/v1/_test/validation')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('errors.0.pointer', '/lines/0/qty')
        ->assertJsonPath('errors.0.message', 'must be > 0');
});

it('renders validator->validate() failures with snake_case rule codes', function () {
    $response = $this->getJson('/v1/_test/validation-rules')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonFragment(['pointer' => '/lines/0/qty', 'code' => 'min'])
        ->assertJsonFragment(['pointer' => '/name', 'code' => 'required']);

    foreach ($response->json('errors') as $error) {
        expect($error['message'])->toBeString()->not->toBe('');
    }
});

it('renders unknown routes as 404 problem', function () {
    $this->getJson('/v1/does-not-exist')
        ->assertStatus(404)
        ->assertJsonPath('status', 404)
        ->assertJsonPath('code', 'not_found')
        ->assertJsonPath('type', 'https://einvoice.billplz.com/problems/not_found');
});

it('hides internal error details when debug is off', function () {
    config(['app.debug' => false]);
    $this->getJson('/v1/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('title', 'Internal Server Error')
        ->assertJsonMissing(['detail' => 'secret detail']);
});
