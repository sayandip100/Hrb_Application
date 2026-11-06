<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgeRelaxation extends Model
{
    use HasFactory;

    protected $table = 'caste_tab';

    protected $primaryKey = 'caste_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'caste_id' => 'integer',
            'caste_cat' => 'integer',
            'caste_parent' => 'integer',
            'caste_attach' => 'integer',
            'caste_status' => 'integer',
            'caste_createby' => 'integer',
            'caste_createdate' => 'datetime',
        ];
    }
}
