<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ApiKey> */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $plain = 'ek_test_'.Str::random(40);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'prefix' => substr($plain, 0, 12),
            'key_hash' => hash('sha256', $plain),
            'environment' => Environment::Sandbox,
            'abilities' => ApiKey::ABILITIES,
        ];
    }
}
