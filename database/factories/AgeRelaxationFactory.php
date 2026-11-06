<?php

namespace Database\Factories;

use App\Models\AgeRelaxation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AgeRelaxation> */
class AgeRelaxationFactory extends Factory
{
    protected $model = AgeRelaxation::class;

    public function definition(): array
    {
        return [
            'caste_name' => fake()->unique()->sentence(4),
            'caste_cat' => 8,
            'caste_parent' => null,
            'caste_attach' => null,
            'caste_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'caste_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'caste_status' => 1,
        ];
    }
}
