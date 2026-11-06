<?php

namespace Database\Factories;

use App\Models\PoliceStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PoliceStation> */
class PoliceStationFactory extends Factory
{
    protected $model = PoliceStation::class;

    public function definition(): array
    {
        return [
            'ps_name' => fake()->city(),
            'ps_code' => null,
            'ps_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'ps_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'ps_dist_master' => fn (array $attributes): string => DistrictFactory::new()->create(['dist_createby' => $attributes['ps_createby']])->district_code,
            'ps_modifydate' => null, 'ps_modifyby' => null, 'ps_status' => 1,
        ];
    }
}
