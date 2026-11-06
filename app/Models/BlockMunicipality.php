<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockMunicipality extends Model
{
    use HasFactory;

    protected $table = 'block_master';

    protected $primaryKey = 'block_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'block_id' => 'integer', 'district_id' => 'integer', 'subd_id' => 'integer',
            'block_status' => 'integer', 'block_createby' => 'integer', 'block_modifyby' => 'integer',
            'block_createdate' => 'datetime', 'block_modifydate' => 'datetime',
        ];
    }

    public function subdivision(): BelongsTo
    {
        return $this->belongsTo(Subdivision::class, 'subd_id', 'subdiv_id');
    }

    public function scopeForMasterData(Builder $query): Builder
    {
        return $query->whereIn('district_id', District::blockCodeMap()->keys()->all());
    }
}
