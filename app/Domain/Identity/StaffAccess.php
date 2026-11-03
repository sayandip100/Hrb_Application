<?php

namespace App\Domain\Identity;

use App\Models\StaffUser;

class StaffAccess
{
    public function allows(StaffUser $staff, string $ip): bool
    {
        $staff->loadMissing('role');

        if ($staff->user_status !== 1 || $staff->role?->mu_status !== 1) {
            return false;
        }

        // Preserve the legacy Super-Admin exception to staff entry restrictions.
        if ($staff->u_type === 1) {
            return true;
        }

        $start = $staff->entry_startdate?->format('Y-m-d');
        $end = $staff->entry_enddate?->format('Y-m-d');
        $startTime = $staff->entry_starttime;
        $endTime = $staff->entry_endtime;
        $allowedIp = $staff->user_mac_address;

        if (! $start || ! $end || ! $startTime || ! $endTime || ! $allowedIp) {
            return false;
        }

        if (! filter_var($allowedIp, FILTER_VALIDATE_IP) || inet_pton($ip) !== inet_pton($allowedIp)) {
            return false;
        }

        $now = now(config('staff_auth.timezone'));

        return $start <= $now->format('Y-m-d')
            && $end >= $now->format('Y-m-d')
            && $startTime <= $now->format('H:i:s')
            && $endTime >= $now->format('H:i:s');
    }
}
