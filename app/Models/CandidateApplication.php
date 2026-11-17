<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidateApplication extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer', 'fee' => 'decimal:2', 'submitted_at' => 'datetime'];
    }
}
