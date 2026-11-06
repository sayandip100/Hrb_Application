<?php

namespace Database\Factories;

use App\Models\Subdivision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subdivision> */
class SubdivisionFactory extends Factory
{
    protected $model = Subdivision::class;

    public function definition(): array
    {
        return [
            'subdiv_name' => fake()->city(),
            'subdiv_code' => null,
            'subdiv_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'subdiv_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'subdiv_district' => fn (array $attributes): string => DistrictFactory::new()->create(['dist_createby' => $attributes['subdiv_createby']])->district_code,
            'subdiv_modifydate' => null, 'subdiv_modifyby' => null, 'subdiv_status' => 1,
        ];
    }
}
