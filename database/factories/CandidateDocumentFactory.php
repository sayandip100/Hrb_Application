<?php

namespace Database\Factories;

use App\Models\CandidateDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CandidateDocument> */
class CandidateDocumentFactory extends Factory
{
    protected $model = CandidateDocument::class;

    public function definition(): array
    {
        return ['candidate_uid' => CandidateUserFactory::new(), 'purpose' => 'qualification', 'path' => 'candidate-documents/'.fake()->uuid().'.pdf', 'mime' => 'application/pdf', 'size' => 100];
    }
}
