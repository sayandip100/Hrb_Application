<?php

namespace Database\Factories;

use App\Models\CandidateApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CandidateApplication> */
class CandidateApplicationFactory extends Factory
{
    protected $model = CandidateApplication::class;

    public function definition(): array
    {
        return ['candidate_uid' => CandidateUserFactory::new(), 'payload' => [], 'version' => 1, 'state' => 'draft'];
    }
}
