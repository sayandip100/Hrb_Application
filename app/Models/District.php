<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class District extends Model
{
    use HasFactory;

    protected $table = 'district_master';

    protected $primaryKey = 'district_id';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'district_id' => 'integer', 'state_id' => 'integer', 'district_status' => 'integer',
            'dist_createby' => 'integer', 'dist_modifyby' => 'integer',
            'dist_createdate' => 'datetime', 'dist_modifydate' => 'datetime',
        ];
    }

    public function scopeSelectableForSubdivisions(Builder $query): Builder
    {
        return $query->where('district_status', 1)->whereRaw('LENGTH(district_code) BETWEEN 1 AND 10')
            ->whereIn('district_code', static::query()->select('district_code')->groupBy('district_code')->havingRaw('COUNT(*) = 1'));
    }

    /** The legacy block table stores the numeric district code in its district_id column. */
    public function blockCode(): ?int
    {
        $code = (string) $this->district_code;
        if (! preg_match('/\A[0-9]+\z/', $code)) {
            return null;
        }
        $digits = ltrim($code, '0');
        if (strlen($digits) > 10 || (strlen($digits) === 10 && strcmp($digits, '2147483647') > 0)) {
            return null;
        }

        return (int) $code;
    }

    /** @return Collection<int, District> */
    public static function blockCodeMap(): Collection
    {
        return static::query()->get(['district_id', 'district_code', 'district_name', 'district_status'])
            ->filter(fn (District $district): bool => $district->blockCode() !== null)
            ->groupBy(fn (District $district): int => $district->blockCode())
            ->filter(fn (Collection $districts): bool => $districts->count() === 1)
            ->map(fn (Collection $districts): District => $districts->first());
    }
}
