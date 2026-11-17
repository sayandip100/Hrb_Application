<?php

namespace Database\Factories;

use App\Models\CandidateUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<CandidateUser> */
class CandidateUserFactory extends Factory
{
    protected $model = CandidateUser::class;

    public function definition(): array
    {
        return ['f_applied_for' => fn (): string => AdvertisementFactory::new()->create()->adv_auto_genno,
            'f_application_no' => fake()->unique()->numerify('C##################'), 'f_mobile' => fake()->unique()->numerify('9#########'),
            'f_full_name' => fake()->name(), 'f_email' => fake()->unique()->safeEmail(), 'f_password' => Hash::make('Candidate123!'),
            'f_registered' => 1, 'f_status' => 1, 'f_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s')];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (CandidateUser $candidate): void {
            DB::table('f_user_details')->insert(['fu_master' => $candidate->f_uid]);
            DB::table('candidate_result_tab')->insert(['cr_application_master' => $candidate->f_application_no]);
        });
    }
}
