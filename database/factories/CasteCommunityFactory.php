<?php

namespace Database\Factories;

use App\Models\CasteCommunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CasteCommunity> */
class CasteCommunityFactory extends Factory
{
    protected $model = CasteCommunity::class;

    public function definition(): array
    {
        return [
            'csdetail_name' => fake()->sentence(3),
            'csdetail_master' => AgeRelaxationFactory::new()->state(['caste_cat' => 2]),
            'csdetail_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'csdetail_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'csdetail_modifydate' => null,
            'csdetail_modifyby' => null,
            'csdetail_status' => 1,
        ];
    }
}
