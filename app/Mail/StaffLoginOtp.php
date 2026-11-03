<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class StaffLoginOtp extends Mailable
{
    public function __construct(public string $otp, public int $expiresInSeconds) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'WBHRB - Admin Login');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.staff-login-otp-html', text: 'mail.staff-login-otp');
    }
}
