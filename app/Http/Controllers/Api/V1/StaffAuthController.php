<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\StaffOtpService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\RequestStaffOtpRequest;
use App\Http\Requests\Staff\VerifyStaffOtpRequest;
use App\Http\Resources\StaffUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StaffAuthController extends Controller
{
    public function requestOtp(RequestStaffOtpRequest $request, StaffOtpService $otp): JsonResponse
    {
        abort_if(Auth::guard('staff')->check(), 409, 'You are already signed in.');
        $data = $request->validated();
        $challenge = $otp->issue($data['mobile'], (int) $data['role_id'], $request->session()->getId(), $request->ip());

        return response()->json(['data' => $challenge], 202)->header('Cache-Control', 'no-store');
    }

    public function login(VerifyStaffOtpRequest $request, StaffOtpService $otp): StaffUserResource
    {
        abort_if(Auth::guard('staff')->check(), 409, 'You are already signed in.');
        $data = $request->validated();
        $staff = $otp->verify($data['challenge_id'], $data['otp'], $request->session()->getId(), $request->ip());
        Auth::guard('staff')->login($staff, false);
        $request->session()->regenerate();

        return new StaffUserResource($staff);
    }

    public function me(): StaffUserResource
    {
        return new StaffUserResource(Auth::guard('staff')->user());
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout successful.']);
    }
}
