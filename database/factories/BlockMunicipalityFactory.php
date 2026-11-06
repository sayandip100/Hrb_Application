<?php

namespace Database\Factories;

use App\Models\BlockMunicipality;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BlockMunicipality> */
class BlockMunicipalityFactory extends Factory
{
    protected $model = BlockMunicipality::class;

    public function definition(): array
    {
        return [
            'block_name' => fake()->city(), 'block_type' => 'Block', 'subd_id' => null,
            'block_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'block_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'district_id' => fn (array $attributes): int => DistrictFactory::new()->create(['dist_createby' => $attributes['block_createby']])->blockCode(),
            'block_modifydate' => null, 'block_modifyby' => null, 'block_status' => 1, 'block_code' => null,
        ];
    }
}
