<?php

namespace Tests\Fakes;

use App\Domain\Identity\OtpSender;
use RuntimeException;

class FakeOtpSender implements OtpSender
{
    /** @var list<array{email: string, otp: string}> */
    public array $messages = [];

    public bool $failDelivery = false;

    public function isAvailable(): bool
    {
        return true;
    }

    public function sendOtp(string $email, string $otp): void
    {
        if ($this->failDelivery) {
            throw new RuntimeException('Synthetic delivery failure.');
        }

        $this->messages[] = ['email' => $email, 'otp' => $otp];
    }

    public function lastOtp(): string
    {
        return $this->messages[array_key_last($this->messages)]['otp'];
    }
}
