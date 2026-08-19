<?php

namespace Database\Factories;

use App\Models\ServiceToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceToken> */
class ServiceTokenFactory extends Factory
{
    protected $model = ServiceToken::class;

    public function definition(): array
    {
        return [
            'name' => 'svc-'.Str::lower(Str::random(6)),
            'token_hash' => hash('sha256', Str::random(40)),
            'abilities' => ['*'],
        ];
    }
}
