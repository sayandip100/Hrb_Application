<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdvertisementMark extends Model
{
    use HasFactory;

    protected $table = 'advertisement_marks';

    protected $primaryKey = 'amark_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amark_academic' => 'float', 'amark_experience' => 'float',
            'amark_interview' => 'float', 'amark_written' => 'float'];
    }
}
