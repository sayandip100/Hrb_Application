<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class CandidateUser extends Authenticatable
{
    use HasFactory;

    protected $table = 'frontend_users';

    protected $primaryKey = 'f_uid';

    public $timestamps = false;

    protected $hidden = ['f_password', 'f_otp', 'f_email_otp', 'f_otp_count', 'f_otp_sendtime'];

    protected function casts(): array
    {
        return ['f_uid' => 'integer', 'f_status' => 'integer', 'f_registered' => 'integer'];
    }

    public function getAuthPasswordName(): string
    {
        return 'f_password';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
