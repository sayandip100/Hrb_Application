<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CandidateRegistrationOtp extends Mailable
{
    public function __construct(public string $otp) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'WBHRB - Candidate Registration');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.candidate-registration-otp');
    }
}
