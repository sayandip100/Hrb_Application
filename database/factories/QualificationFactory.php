<?php

namespace Database\Factories;

use App\Models\Qualification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Qualification> */
class QualificationFactory extends Factory
{
    protected $model = Qualification::class;

    public function definition(): array
    {
        return [
            'qm_name' => fake()->words(3, true),
            'qm_r_master' => null,
            'qm_details' => null,
            'qm_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'qm_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'qm_status' => 1,
        ];
    }
}
