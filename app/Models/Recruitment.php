<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recruitment extends Model
{
    use HasFactory;

    protected $table = 'recruitment_master_tab';

    protected $primaryKey = 'rm_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'rm_id' => 'integer',
            'rm_status' => 'integer',
            'rm_createby' => 'integer',
            'rm_createdate' => 'datetime',
        ];
    }
}
