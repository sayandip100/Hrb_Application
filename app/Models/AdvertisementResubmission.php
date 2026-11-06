<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdvertisementResubmission extends Model
{
    use HasFactory;

    protected $table = 'advertisement_resubmit_tab';

    protected $primaryKey = 'adv_resub_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['adv_resub_id' => 'integer', 'adv_resub_cat' => 'integer',
            'adv_resub_status' => 'integer', 'adv_resub_chk_stat' => 'integer',
            'adv_resub_starttime' => 'datetime', 'adv_resub_endtime' => 'datetime',
            'adv_resub_createdate' => 'datetime', 'adv_resub_modifydate' => 'datetime', 'adv_resub_chk_start' => 'datetime'];
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class, 'adv_resub_master', 'adv_auto_genno');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ResubmissionCandidate::class, 'resubmission_id', 'adv_resub_id');
    }
}
