<?php

namespace Database\Factories;

use App\Models\Recruitment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Recruitment> */
class RecruitmentFactory extends Factory
{
    protected $model = Recruitment::class;

    public function definition(): array
    {
        return [
            'rm_name' => fake()->unique()->sentence(4),
            'rm_details' => null,
            'rm_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'rm_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'rm_status' => 1,
        ];
    }

    public function locked(): static
    {
        return $this->state(fn (): array => ['rm_status' => 0]);
    }
}
