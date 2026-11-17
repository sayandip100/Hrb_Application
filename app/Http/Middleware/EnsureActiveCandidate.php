<?php

namespace App\Http\Middleware;

use App\Models\CandidateUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveCandidate
{
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = CandidateUser::query()->find(Auth::guard('candidate')->id());
        abort_unless($candidate && $candidate->f_status === 1 && $candidate->f_registered === 1, 401, 'Unauthenticated.');
        abort_if(DB::table('f_user_details')->where('fu_master', $candidate->f_uid)->where('fu_cancel_stat', 1)->exists(), 403, 'This application is cancelled.');
        Auth::guard('candidate')->setUser($candidate);

        return $next($request);
    }
}
