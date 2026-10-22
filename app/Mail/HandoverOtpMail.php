<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HandoverOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $stage,
        public int $expiresInMinutes = 5
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CashRoute Cash Handover Verification',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.handover-otp',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
