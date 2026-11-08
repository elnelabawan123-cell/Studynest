<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeacherCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;
    public string $username;
    public string $email;
    public string $password;
    public string $loginUrl;
    /** 'created' = brand-new account, 'reset' = password was reset */
    public string $context;

    public function __construct(
        string $name,
        string $username,
        string $email,
        string $password,
        string $context = 'created',
        ?string $loginUrl = null
    ) {
        $this->name     = $name;
        $this->username = $username;
        $this->email    = $email;
        $this->password = $password;
        $this->context  = $context;
        $this->loginUrl = $loginUrl ?? config('app.frontend_url', config('app.url'));
    }

    public function envelope(): Envelope
    {
        $subject = $this->context === 'reset'
            ? 'Your StudyNest Password Has Been Reset'
            : 'Your StudyNest Teacher Account Credentials';

        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.teacher-credentials',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}