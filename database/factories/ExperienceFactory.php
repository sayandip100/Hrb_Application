<?php

namespace Database\Factories;

use App\Models\Experience;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Experience> */
class ExperienceFactory extends Factory
{
    protected $model = Experience::class;

    public function definition(): array
    {
        return [
            'expset_name' => fake()->unique()->sentence(4),
            'expset_details' => null,
            'expset_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'expset_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'expset_modifydate' => null,
            'expset_modifyby' => null,
            'expset_status' => 1,
        ];
    }
}
