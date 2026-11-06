<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Advertisement extends Model
{
    use HasFactory;

    protected $table = 'advertisement_master';

    protected $primaryKey = 'adv_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['adv_id' => 'integer', 'adv_recruit_master' => 'integer',
            'adv_total_recruit' => 'integer', 'adv_status' => 'integer', 'adv_activated' => 'integer',
            'adv_createby' => 'integer', 'adv_modify_by' => 'integer',
            'adv_start_time' => 'datetime', 'adv_end_time' => 'datetime',
            'adv_createdate' => 'datetime', 'adv_modifydate' => 'datetime'];
    }

    public function recruitment(): BelongsTo
    {
        return $this->belongsTo(Recruitment::class, 'adv_recruit_master', 'rm_id');
    }

    public function marks(): HasOne
    {
        return $this->hasOne(AdvertisementMark::class, 'amark_adv_master', 'adv_auto_genno')->latestOfMany('amark_id');
    }
}
