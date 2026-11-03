<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffBrowserSession
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->hasSession(), 400, 'A stateful browser session is required.');

        return $next($request);
    }
}
