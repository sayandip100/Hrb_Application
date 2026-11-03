<?php

namespace App\Domain\Identity;

use App\Mail\StaffLoginOtp;
use Illuminate\Support\Facades\Mail;
use LogicException;

class EmailOtpSender implements OtpSender
{
    public function isAvailable(): bool
    {
        return config('mail.default') === 'smtp'
            && config('mail.mailers.smtp.transport') === 'smtp'
            && filled(config('mail.mailers.smtp.host'))
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function sendOtp(string $email, string $otp): void
    {
        if (! $this->isAvailable()) {
            throw new LogicException('Staff OTP email delivery is not configured yet.');
        }

        Mail::mailer('smtp')->to($email)->send(new StaffLoginOtp($otp, (int) config('staff_auth.otp_ttl')));
    }
}
