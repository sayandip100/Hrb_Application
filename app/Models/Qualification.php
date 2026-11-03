<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Qualification extends Model
{
    use HasFactory;

    protected $table = 'qualification_master';

    protected $primaryKey = 'qm_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qm_id' => 'integer',
            'qm_r_master' => 'integer',
            'qm_status' => 'integer',
            'qm_createby' => 'integer',
            'qm_createdate' => 'datetime',
        ];
    }

    public function recruitment(): BelongsTo
    {
        return $this->belongsTo(Recruitment::class, 'qm_r_master', 'rm_id');
    }
}
