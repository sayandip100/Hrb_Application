<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CasteIssuingAuthority extends Model
{
    use HasFactory;

    protected $table = 'caste_issuing_auth_tab';

    protected $primaryKey = 'cia_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'cia_id' => 'integer',
            'cia_status' => 'integer',
            'cia_createby' => 'integer',
            'cia_modifyby' => 'integer',
            'cia_createdate' => 'datetime',
            'cia_modifydate' => 'datetime',
        ];
    }
}
