<?php

namespace Database\Factories;

use App\Models\CasteIssuingAuthority;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CasteIssuingAuthority> */
class CasteIssuingAuthorityFactory extends Factory
{
    protected $model = CasteIssuingAuthority::class;

    public function definition(): array
    {
        return [
            'cia_name' => fake()->unique()->sentence(4),
            'cia_createdate' => now('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'cia_createby' => StaffUserFactory::new()->for(StaffRoleFactory::new()->superAdmin(), 'role'),
            'cia_modifydate' => null,
            'cia_modifyby' => null,
            'cia_status' => 1,
        ];
    }
}
