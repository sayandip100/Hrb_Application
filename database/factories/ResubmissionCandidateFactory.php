<?php

namespace Database\Factories;

use App\Models\ResubmissionCandidate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<ResubmissionCandidate> */
class ResubmissionCandidateFactory extends Factory
{
    protected $model = ResubmissionCandidate::class;

    public function definition(): array
    {
        return ['resubmission_id' => function (): int {
            $window = AdvertisementResubmissionFactory::new()->create();
            DB::table('advertisement_resubmission_batches')->insert([
                'resubmission_id' => $window->getKey(), 'eligible_candidates' => 1, 'created_at' => now('Asia/Kolkata'),
            ]);

            return $window->getKey();
        }, 'application_no' => fake()->unique()->numerify('APP########'), 'candidate_uid' => 1,
            'email' => fake()->safeEmail(), 'snapshot' => [], 'notification_status' => 'pending',
            'attempts' => 0, 'created_at' => now('Asia/Kolkata')];
    }
}
