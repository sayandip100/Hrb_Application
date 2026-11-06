<?php

namespace Database\Factories;

use App\Models\AdvertisementDraft;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdvertisementDraft> */
class AdvertisementDraftFactory extends Factory
{
    protected $model = AdvertisementDraft::class;

    public function definition(): array
    {
        return ['reference' => fake()->unique()->numerify('A'.now('Asia/Kolkata')->format('dmYhis').'####'), 'state' => 'draft', 'payload' => [],
            'version' => 1, 'created_by' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role')];
    }
}
