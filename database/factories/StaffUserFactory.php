<?php

namespace Database\Factories;

use App\Models\StaffUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffUser> */
class StaffUserFactory extends Factory
{
    protected $model = StaffUser::class;

    public function definition(): array
    {
        return [
            'u_type' => StaffRoleFactory::new(),
            'mobile' => fake()->unique()->numerify('9#########'),
            'firstname' => 'Test',
            'lastname' => 'Staff',
            'email' => 'staff@example.test',
            'user_status' => 1,
            'entry_startdate' => now('Asia/Kolkata')->subDay()->format('Y-m-d'),
            'entry_enddate' => now('Asia/Kolkata')->addDay()->format('Y-m-d'),
            'entry_starttime' => '00:00:00',
            'entry_endtime' => '23:59:59',
            'user_mac_address' => '127.0.0.1',
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['user_status' => 0]);
    }

    public function expiredAccess(): static
    {
        return $this->state(fn (): array => [
            'entry_startdate' => now('Asia/Kolkata')->subDays(3)->format('Y-m-d'),
            'entry_enddate' => now('Asia/Kolkata')->subDay()->format('Y-m-d'),
        ]);
    }

    public function unconfiguredAccess(): static
    {
        return $this->state(fn (): array => [
            'entry_startdate' => null,
            'entry_enddate' => null,
            'entry_starttime' => null,
            'entry_endtime' => null,
            'user_mac_address' => null,
        ]);
    }
}
