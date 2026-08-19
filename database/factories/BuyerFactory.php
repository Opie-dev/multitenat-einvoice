<?php

namespace Database\Factories;

use App\Enums\IdType;
use App\Models\Buyer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Buyer> */
class BuyerFactory extends Factory
{
    protected $model = Buyer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'tin' => 'IG'.fake()->numerify('###########'),
            'id_type' => IdType::Nric,
            'id_number' => fake()->numerify('############'),
            'email' => fake()->safeEmail(),
            'country_code' => 'MYS',
            'general_public' => false,
        ];
    }
}
