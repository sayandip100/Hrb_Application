<?php

namespace App\Http\Middleware;

use App\Models\StaffUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $staff = Auth::guard('staff')->user();
        abort_unless($staff instanceof StaffUser, 401, 'Unauthenticated.');
        abort_unless(in_array($staff->u_type, array_map('intval', $roles), true), 403, 'You are not permitted to perform this operation.');

        return $next($request);
    }
}
