<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class StudentCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $studentName,
        public string $studentNumber,
        public string $temporaryPassword,
    ) {}

    public function build()
    {
        return $this
            ->subject('Your StudyNest Student Account Credentials')
            ->markdown('emails.student-credentials')
            ->with([
                'studentName'   => $this->studentName,
                'studentNumber' => $this->studentNumber,
                'password'      => $this->temporaryPassword,
                'loginUrl'      => config('app.frontend_url', config('app.url')),
            ]);
    }
}