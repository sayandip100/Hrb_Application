<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ResubmissionInvitation extends Mailable
{
    public function __construct(public string $advertisementNumber, public string $postName,
        public string $startsAt, public string $endsAt, public ?string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'WBHRB - Resubmission of Documents');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.resubmission-invitation');
    }
}
