<?php

namespace Database\Factories;

use App\Models\AdvertisementResubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdvertisementResubmission> */
class AdvertisementResubmissionFactory extends Factory
{
    protected $model = AdvertisementResubmission::class;

    public function definition(): array
    {
        return ['adv_resub_master' => fn (): string => AdvertisementFactory::new()->create()->adv_auto_genno,
            'adv_resub_cat' => null, 'adv_resub_starttime' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'adv_resub_endtime' => now('Asia/Kolkata')->addDays(2)->format('Y-m-d H:i:s'),
            'adv_resub_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'adv_resub_createby' => 1, 'adv_resub_status' => 1, 'adv_resub_chk_stat' => 0];
    }
}
