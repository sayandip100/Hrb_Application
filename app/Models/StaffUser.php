<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;



class StaffUser extends Authenticatable
{


    protected $hidden = [
        'otp_verify',
        'otp_count',
        'otp_sendtime',
        'user_mac_address',
    ];

    protected $table = 'user_info';

    protected $primaryKey = 'u_id';

    public const CREATED_AT = 'user_createdate';

    public const UPDATED_AT = 'modify_date';

    protected function casts(): array
    {
        return [
            'u_type' => 'integer',
            'user_status' => 'integer',
            'entry_startdate' => 'date',
            'entry_enddate' => 'date',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(StaffRole::class, 'u_type', 'mu_id');
    }
}
