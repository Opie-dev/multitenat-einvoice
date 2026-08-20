<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Issuer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Owner,
            'issuer_id' => null,
            'invited_at' => now(),
            'last_login_at' => null,
        ];
    }

    /** Pins the user to a vendor role and the given issuer, satisfying the DB CHECK constraint. */
    public function vendor(Issuer $issuer): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Vendor, 'issuer_id' => $issuer->id]);
    }

    public function member(): static
    {
        return $this->state(fn (): array => ['role' => UserRole::Member]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['last_login_at' => now()]);
    }
}
