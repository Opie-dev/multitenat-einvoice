<?php

namespace Database\Factories;

use App\Enums\Environment;
use App\Enums\IdType;
use App\Enums\IssuerStatus;
use App\Enums\LhdnMode;
use App\Models\Issuer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Issuer> */
class IssuerFactory extends Factory
{
    protected $model = Issuer::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->company(),
            'tin' => 'C'.fake()->unique()->numerify('###########'),
            'id_type' => IdType::Brn,
            'id_number' => fake()->numerify('############'),
            'msic_code' => '47911',
            'business_activity_description' => 'Retail sale via internet',
            'address_line1' => fake()->streetAddress(),
            'postcode' => '50000',
            'city' => 'Kuala Lumpur',
            'state_code' => '14',
            'country_code' => 'MYS',
            'email' => fake()->companyEmail(),
            'phone' => '+60123456789',
            'environment' => Environment::Sandbox,
            'lhdn_mode' => LhdnMode::Intermediary,
            'einvoice_required' => true,
            'consolidation_enabled' => false,
            'status' => IssuerStatus::Draft,
        ];
    }

    public function authorized(): static
    {
        return $this->state(['status' => IssuerStatus::Authorized, 'tin_verified_at' => now(), 'authorized_at' => now()]);
    }

    public function active(): static
    {
        return $this->authorized()->state(['status' => IssuerStatus::Active, 'activated_at' => now(), 'certificate_valid_until' => now()->addYear()]);
    }
}
