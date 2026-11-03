<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Discipline extends Model
{
    use HasFactory;

    protected $table = 'category_master';

    protected $primaryKey = 'catm_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'catm_id' => 'integer',
            'catm_master' => 'integer',
            'catm_status' => 'integer',
            'catm_createby' => 'integer',
            'catm_createdate' => 'datetime',
        ];
    }

    public function recruitment(): BelongsTo
    {
        return $this->belongsTo(Recruitment::class, 'catm_master', 'rm_id');
    }
}
