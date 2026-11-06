<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Experience extends Model
{
    use HasFactory;

    protected $table = 'experience_master_tab';

    protected $primaryKey = 'expset_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'expset_id' => 'integer',
            'expset_status' => 'integer',
            'expset_createby' => 'integer',
            'expset_modifyby' => 'integer',
            'expset_createdate' => 'datetime',
            'expset_modifydate' => 'datetime',
        ];
    }
}
