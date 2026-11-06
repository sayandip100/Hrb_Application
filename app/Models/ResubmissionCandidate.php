<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResubmissionCandidate extends Model
{
    use HasFactory;

    protected $table = 'advertisement_resubmission_candidates';

    public $timestamps = false;

    protected $hidden = ['snapshot', 'email'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'attempts' => 'integer'];
    }

    public function resubmission(): BelongsTo
    {
        return $this->belongsTo(AdvertisementResubmission::class, 'resubmission_id', 'adv_resub_id');
    }
}
