<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoliceStation extends Model
{
    use HasFactory;

    protected $table = 'police_station_tab';

    protected $primaryKey = 'ps_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'ps_id' => 'integer', 'ps_status' => 'integer',
            'ps_createby' => 'integer', 'ps_modifyby' => 'integer',
            'ps_createdate' => 'datetime', 'ps_modifydate' => 'datetime',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'ps_dist_master', 'district_code');
    }

    public function scopeForMasterData(Builder $query): Builder
    {
        return $query->whereHas('district');
    }
}
