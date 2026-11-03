<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StaffRole extends Model
{
    protected $table = 'master_user_type';

    protected $primaryKey = 'mu_id';

    public const CREATED_AT = 'mu_createdate';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'mu_status' => 'integer',
        ];
    }

    public function staffUsers(): HasMany
    {
        return $this->hasMany(StaffUser::class, 'u_type', 'mu_id');
    }
}
