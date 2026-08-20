<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginLinkMail extends Mailable
{
    use SerializesModels;

    /** @param string $token Plaintext magic-link token. Only lives for the duration of this mail send — never persisted. */
    public function __construct(
        public readonly User $user,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Billplz e-Invoice sign-in link');
    }

    public function content(): Content
    {
        // markdown, not view: the template uses <x-mail::message> components,
        // which only resolve through the "mail" view namespace that Laravel
        // registers for markdown mailables (Illuminate\Mail\Markdown::render()).
        return new Content(
            markdown: 'mail.login-link',
            with: [
                'name' => $this->user->name,
                'url' => route('login.consume', $this->token),
            ],
        );
    }
}
