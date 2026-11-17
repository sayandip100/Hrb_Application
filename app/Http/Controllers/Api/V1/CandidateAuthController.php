<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CandidateRegistrationService;
use App\Domain\Identity\PublicId;
use App\Http\Controllers\Controller;
use App\Http\Requests\CandidateLoginRequest;
use App\Http\Requests\CandidateRegistrationRequest;
use App\Models\Advertisement;
use App\Models\CandidateUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CandidateAuthController extends Controller
{
    public function requestOtp(CandidateRegistrationRequest $request, CandidateRegistrationService $service): JsonResponse
    {
        abort_if(Auth::guard('candidate')->check(), 409, 'You are already signed in.');

        return response()->json(['data' => $service->issue($request->validated(), $request->session()->getId())], 202)->header('Cache-Control', 'no-store');
    }

    public function register(CandidateRegistrationRequest $request, CandidateRegistrationService $service): JsonResponse
    {
        abort_if(Auth::guard('candidate')->check(), 409, 'You are already signed in.');
        $candidate = $service->register($request->validated(), $request->session()->getId());
        Auth::guard('candidate')->login($candidate, false);
        $request->session()->regenerate();

        return response()->json(['data' => $this->profile($candidate), 'message' => 'Candidate registration successful.'], 201)->header('Cache-Control', 'no-store');
    }

    public function login(CandidateLoginRequest $request): JsonResponse
    {
        abort_if(Auth::guard('candidate')->check(), 409, 'You are already signed in.');
        $data = $request->validated();
        $key = 'candidate-login:'.hash('sha256', $request->ip().'|'.$data['mobile'].'|'.PublicId::decode('advertisement', $data['advertisement_id']));
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many login attempts. Please retry later.');
        RateLimiter::hit($key, 60);
        $advertisement = Advertisement::query()->find(PublicId::decode('advertisement', $data['advertisement_id']));
        $matches = CandidateUser::query()->where('f_applied_for', $advertisement?->adv_auto_genno)->where('f_mobile', $data['mobile'])->where('f_status', 1)->where('f_registered', 1)->limit(2)->get();
        $candidate = $matches->count() === 1 ? $matches->first() : null;
        $hash = $candidate?->f_password ?? '$2y$12$R9h/cIPz0gi.URNNX3kh2OPST9/PgBkqquzi.Ss7KIUgO2t0jWMUW';
        $legacyKey = config('candidate_auth.legacy_password_key');
        $legacy = $candidate && filled($legacyKey) && preg_match('/\A[0-9a-f]{128}\z/', $hash) && hash_equals($hash, hash('sha512', $data['password'].$legacyKey));
        $valid = $legacy || ((str_starts_with($hash, '$2') || str_starts_with($hash, '$argon2')) && Hash::check($data['password'], $hash));
        if (! $candidate || ! $valid) {
            throw ValidationException::withMessages(['mobile' => 'The login credentials are invalid.']);
        }
        if ($legacy || Hash::needsRehash($hash)) {
            $candidate->f_password = Hash::make($data['password']);
            $candidate->save();
        }
        RateLimiter::clear($key);
        Auth::guard('candidate')->login($candidate, false);
        $request->session()->regenerate();

        return $this->me();
    }

    private function profile(CandidateUser $candidate): array
    {
        return ['id' => PublicId::encode('candidate', $candidate->f_uid), 'application_number' => $candidate->f_application_no,
            'name' => $candidate->f_full_name, 'mobile' => $candidate->f_mobile, 'email' => $candidate->f_email,
            'advertisement_id' => PublicId::encode('advertisement', Advertisement::query()->where('adv_auto_genno', $candidate->f_applied_for)->value('adv_id'))];
    }

    public function me(): JsonResponse
    {
        return response()->json(['data' => $this->profile(Auth::guard('candidate')->user())])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('candidate')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout successful.']);
    }
}
