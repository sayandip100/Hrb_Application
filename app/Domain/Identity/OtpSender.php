<?php

namespace App\Domain\Identity;

interface OtpSender
{
    public function isAvailable(): bool;

    public function sendOtp(string $email, string $otp): void;
}
