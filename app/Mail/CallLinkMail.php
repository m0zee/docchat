<?php

namespace App\Mail;

use App\Models\Call;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CallLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Call $call, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __(':workspace would like to talk', ['workspace' => $this->call->workspace->name]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.call-link');
    }
}
