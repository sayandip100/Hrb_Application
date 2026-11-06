<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subdivision extends Model
{
    use HasFactory;

    protected $table = 'subdivision_tab';

    protected $primaryKey = 'subdiv_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'subdiv_id' => 'integer', 'subdiv_status' => 'integer',
            'subdiv_createby' => 'integer', 'subdiv_modifyby' => 'integer',
            'subdiv_createdate' => 'datetime', 'subdiv_modifydate' => 'datetime',
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'subdiv_district', 'district_code');
    }

    public function scopeForMasterData(Builder $query): Builder
    {
        return $query->whereHas('district');
    }
}
