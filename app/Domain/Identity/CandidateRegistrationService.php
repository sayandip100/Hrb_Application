<?php

namespace App\Domain\Identity;

use App\Mail\CandidateRegistrationOtp;
use App\Models\Advertisement;
use App\Models\CandidateApplication;
use App\Models\CandidateUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CandidateRegistrationService
{
    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public function issue(array $data, string $session): array
    {
        $advertisement = CandidateApplicationService::publicAdvertisement($data['advertisement_id'], true);
        abort_unless(config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')), 503, 'Candidate registration email delivery is not configured.');
        $data['email'] = mb_strtolower($data['email']);
        $cache = Cache::store(config('candidate_auth.cache_store'));
        $identity = $this->digest($advertisement->adv_id.'|'.$data['mobile'].'|'.$data['email']);
        abort_unless($cache->add('candidate-otp:cooldown:'.$identity, true, 60), 429, 'Please wait before requesting another code.');
        $challenge = (string) Str::uuid();
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $record = ['data' => $data, 'reference' => $advertisement->adv_auto_genno, 'session' => $this->digest($session),
            'otp' => $this->digest($challenge.'|'.$otp), 'expires' => now()->timestamp + 300, 'attempts' => 0, 'identity' => $identity];
        $cache->put('candidate-otp:current:'.$identity, $challenge, 300);
        $cache->put('candidate-otp:'.$challenge, $record, 300);
        $duplicate = CandidateUser::query()->where('f_applied_for', $advertisement->adv_auto_genno)
            ->where(fn ($q) => $q->where('f_mobile', $data['mobile'])->orWhereRaw('LOWER(f_email) = ?', [$data['email']]))->exists();
        if (! $duplicate) {
            try {
                Mail::mailer('smtp')->to($data['email'])->send(new CandidateRegistrationOtp($otp));
            } catch (Throwable) {
                $cache->forget('candidate-otp:'.$challenge);
                abort(503, 'Registration email could not be sent. Please retry later.');
            }
        }

        return ['challenge_id' => $challenge, 'expires_in' => 300, 'message' => 'If registration is available, a verification code will be sent to your email.'];
    }

    public function register(array $data, string $session): CandidateUser
    {
        $cache = Cache::store(config('candidate_auth.cache_store'));

        return $cache->lock('candidate-otp:lock:'.$data['challenge_id'], 30)->block(3, function () use ($cache, $data, $session): CandidateUser {
            $key = 'candidate-otp:'.$data['challenge_id'];
            $record = $cache->get($key);
            if (! is_array($record) || $record['expires'] <= now()->timestamp || ! hash_equals($record['session'], $this->digest($session))
                || $cache->get('candidate-otp:current:'.$record['identity']) !== $data['challenge_id']) {
                $this->invalid();
            }
            if (! hash_equals($record['otp'], $this->digest($data['challenge_id'].'|'.$data['otp']))) {
                $record['attempts']++;
                if ($record['attempts'] >= 5) {
                    $cache->forget($key);
                } else {
                    $cache->put($key, $record, $record['expires'] - now()->timestamp);
                } $this->invalid();
            }
            $candidate = DB::transaction(function () use ($record, $data): CandidateUser {
                $advertisement = Advertisement::query()->where('adv_auto_genno', $record['reference'])->lockForUpdate()->firstOrFail();
                abort_unless(CandidateApplicationService::isOpen($advertisement), 409, 'This advertisement is not open for applications.');
                $input = $record['data'];
                if (CandidateUser::query()->where('f_applied_for', $record['reference'])
                    ->where(fn ($q) => $q->where('f_mobile', $input['mobile'])->orWhereRaw('LOWER(f_email) = ?', [$input['email']]))->exists()) {
                    $this->invalid();
                }
                $candidate = new CandidateUser;
                $candidate->f_applied_for = $record['reference'];
                do {
                    $number = 'C'.now('Asia/Kolkata')->format('dmYhis').str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
                } while (CandidateUser::query()->where('f_application_no', $number)->exists());
                $candidate->f_application_no = $number;
                $candidate->f_full_name = $input['name'];
                $candidate->f_mobile = $input['mobile'];
                $candidate->f_email = $input['email'];
                $candidate->f_password = Hash::make($data['password']);
                $candidate->f_status = 1;
                $candidate->f_registered = 1;
                $candidate->f_set_pass = 1;
                $candidate->f_setpass_datetime = now('Asia/Kolkata')->format('Y-m-d H:i:s');
                $candidate->f_createdate = $candidate->f_setpass_datetime;
                $candidate->save();
                DB::table('f_user_details')->insert(['fu_master' => $candidate->f_uid]);
                DB::table('candidate_result_tab')->insert(['cr_application_master' => $number, 'cr_approval' => 'NotChecked']);
                $application = new CandidateApplication;
                $application->candidate_uid = $candidate->f_uid;
                $application->payload = [];
                $application->version = 1;
                $application->state = 'draft';
                $application->save();

                return $candidate;
            }, 3);
            $cache->forget($key);

            return $candidate;
        });
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['otp' => 'The registration code is invalid, expired, or registration is unavailable.']);
    }
}
