<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasteCommunity extends Model
{
    use HasFactory;

    protected $table = 'caste_details_tab';

    protected $primaryKey = 'csdetail_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'csdetail_id' => 'integer',
            'csdetail_master' => 'integer',
            'csdetail_status' => 'integer',
            'csdetail_createby' => 'integer',
            'csdetail_modifyby' => 'integer',
            'csdetail_createdate' => 'datetime',
            'csdetail_modifydate' => 'datetime',
        ];
    }

    public function caste(): BelongsTo
    {
        return $this->belongsTo(AgeRelaxation::class, 'csdetail_master', 'caste_id');
    }

    public function scopeForMasterData(Builder $query): Builder
    {
        return $query->whereHas('caste', fn (Builder $caste): Builder => $caste->where('caste_cat', 2));
    }
}
