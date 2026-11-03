<?php

namespace Database\Factories;

use App\Models\StaffRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffRole> */
class StaffRoleFactory extends Factory
{
    protected $model = StaffRole::class;

    public function definition(): array
    {
        return [
            'mu_id' => 2,
            'mu_name' => 'Checker-LVL-1',
            'mu_createdate' => now(),
            'mu_createby' => 1,
            'mu_status' => 1,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['mu_status' => 0]);
    }

    public function superAdmin(): static
    {
        return $this->state(fn (): array => ['mu_id' => 1, 'mu_name' => 'Super-Admin']);
    }
}
