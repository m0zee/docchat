<?php

namespace App\Mail;

use App\Models\VoiceClone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VoiceConsentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public VoiceClone $clone, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':workspace asks to use a copy of your voice', ['workspace' => $this->clone->workspace->name]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.voice-consent');
    }
}
