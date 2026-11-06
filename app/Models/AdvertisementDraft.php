<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementDraft extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'version' => 'integer', 'created_by' => 'integer',
            'updated_by' => 'integer', 'submitted_advertisement_id' => 'integer', 'submitted_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by', 'u_id');
    }
}
