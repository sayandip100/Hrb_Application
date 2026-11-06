<?php

namespace Database\Factories;

use App\Models\Advertisement;
use App\Models\Recruitment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Advertisement> */
class AdvertisementFactory extends Factory
{
    protected $model = Advertisement::class;

    public function definition(): array
    {
        return [
            'adv_auto_genno' => fake()->unique()->numerify('A##############'),
            'adv_no' => fake()->unique()->numerify('WBHRB/TEST/#####'),
            'adv_recruit_master' => RecruitmentFactory::new(),
            'adv_start_time' => '2027-01-01 10:00:00', 'adv_end_time' => '2027-02-01 18:00:00',
            'adv_type' => 'New', 'adv_scale_pay' => 'Level 10', 'adv_total_recruit' => 5,
            'adv_category_no' => 1, 'adv_min_age_limit' => '2000-01-01', 'adv_age_limit' => '1980-01-01',
            'adv_has_experience' => 'No', 'adv_qualification_no' => 1, 'adv_experience_no' => 0,
            'adv_source_doc' => 'advertisement.pdf', 'adv_createdate' => now('Asia/Kolkata'),
            'adv_createby' => fn (array $attributes): int => Recruitment::query()->findOrFail($attributes['adv_recruit_master'])->rm_createby,
            'adv_status' => 1, 'adv_activated' => 0,
        ];
    }
}
