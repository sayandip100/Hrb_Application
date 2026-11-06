<?php

namespace Database\Factories;

use App\Models\AdvertisementMark;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdvertisementMark> */
class AdvertisementMarkFactory extends Factory
{
    protected $model = AdvertisementMark::class;

    public function definition(): array
    {
        return ['amark_adv_master' => fn (): string => AdvertisementFactory::new()->create()->adv_auto_genno,
            'amark_academic' => 40, 'amark_experience' => 10, 'amark_interview' => 20,
            'amark_written' => 30, 'amrk_createdate' => now('Asia/Kolkata')];
    }
}
