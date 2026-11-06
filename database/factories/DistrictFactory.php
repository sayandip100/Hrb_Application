<?php

namespace Database\Factories;

use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<District> */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    public function definition(): array
    {
        return [
            'state_id' => 1, 'district_name' => fake()->city(),
            'district_code' => fake()->unique()->numerify('00######'),
            'dist_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'dist_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'district_status' => 1,
        ];
    }
}
