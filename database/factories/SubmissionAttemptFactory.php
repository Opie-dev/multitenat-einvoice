<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Models\Issuer;
use App\Models\SubmissionAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionAttempt> */
class SubmissionAttemptFactory extends Factory
{
    protected $model = SubmissionAttempt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'issuer_id' => Issuer::factory(),
            'operation' => 'submit',
            'environment' => Environment::Sandbox,
            'http_status' => 200,
            'request' => ['documents' => 1],
            'response' => ['ok' => true],
            'duration_ms' => 120,
            'created_at' => now(),
        ];
    }
}
