<?php

namespace App\Http\Middleware;

use App\Domain\Identity\StaffAccess;
use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveStaff
{
    public function __construct(private StaffAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff instanceof StaffUser, 401, 'Unauthenticated.');
        $staff = StaffUser::query()->with('role')->find($staff->getKey());

        if (! $staff || ! $this->access->allows($staff, $request->ip())) {
            Auth::guard('staff')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Staff access is not currently permitted.');
        }

        Auth::guard('staff')->setUser($staff);

        return $next($request);
    }
}
