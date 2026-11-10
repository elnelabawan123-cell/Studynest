<?php

namespace Tests\Unit;

use App\Mail\StudentCredentialsMail;
use App\Mail\TeacherCredentialsMail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class BrevoTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevo.key' => 'xkeysib-test-key-123',
            'services.brevo.endpoint' => 'https://api.brevo.com/v3/smtp/email',
            'mail.from.address' => 'sender@studynest.test',
            'mail.from.name' => 'StudyNest',
        ]);
        Mail::purge('brevo');
    }

    public function test_teacher_credentials_mail_is_sent_via_brevo_http_api(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response([
                'messageId' => '<msg-123@brevo.test>',
            ], 201),
        ]);

        Mail::mailer('brevo')
            ->to('teacher@example.com')
            ->send(new TeacherCredentialsMail(
                name: 'Maria Santos',
                username: 'msantos',
                email: 'teacher@example.com',
                password: 'TempPass99!',
                context: 'created',
                loginUrl: 'https://web-admin-58800.web.app',
            ));

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request->hasHeader('api-key', 'xkeysib-test-key-123')
                && ($data['sender']['email'] ?? null) === 'sender@studynest.test'
                && ($data['sender']['name'] ?? null) === 'StudyNest'
                && ($data['to'][0]['email'] ?? null) === 'teacher@example.com'
                && ($data['subject'] ?? null) === 'Your StudyNest Teacher Account Credentials'
                && is_string($data['htmlContent'] ?? null)
                && str_contains($data['htmlContent'], 'TempPass99!')
                && str_contains($data['htmlContent'], 'msantos');
        });
    }

    public function test_raw_mail_supports_cc_bcc_reply_to_and_attachments(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response([
                'messageId' => '<msg-456@brevo.test>',
            ], 201),
        ]);

        Mail::mailer('brevo')->raw('Plain alert body', function ($message): void {
            $message->to('admin@example.com', 'Admin User')
                ->cc('cc@example.com')
                ->bcc('bcc@example.com')
                ->replyTo('reply@example.com', 'Support')
                ->subject('System Alert')
                ->attachData('hello-csv', 'report.csv', ['mime' => 'text/csv']);
        });

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return ($data['to'] ?? null) === [['email' => 'admin@example.com', 'name' => 'Admin User']]
                && ($data['cc'] ?? null) === [['email' => 'cc@example.com']]
                && ($data['bcc'] ?? null) === [['email' => 'bcc@example.com']]
                && ($data['replyTo'] ?? null) === ['email' => 'reply@example.com', 'name' => 'Support']
                && ($data['subject'] ?? null) === 'System Alert'
                && ($data['textContent'] ?? null) === 'Plain alert body'
                && ($data['attachment'][0]['name'] ?? null) === 'report.csv'
                && ($data['attachment'][0]['content'] ?? null) === base64_encode('hello-csv');
        });
    }

    public function test_student_credentials_mail_renders_html_content(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<stu-1>'], 201),
        ]);

        Mail::mailer('brevo')
            ->to('student@example.com')
            ->send(new StudentCredentialsMail('Juan Dela Cruz', '2026-0001', 'StudPass12!'));

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return ($data['to'][0]['email'] ?? null) === 'student@example.com'
                && ($data['subject'] ?? null) === 'Your StudyNest Student Account Credentials'
                && str_contains((string) ($data['htmlContent'] ?? ''), '2026-0001')
                && str_contains((string) ($data['htmlContent'] ?? ''), 'StudPass12!');
        });
    }

    public function test_missing_api_key_throws_transport_exception(): void
    {
        config(['services.brevo.key' => '']);
        Mail::purge('brevo');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('BREVO_API_KEY is not configured.');

        Mail::mailer('brevo')->raw('Test', function ($message): void {
            $message->to('teacher@example.com')->subject('Hi');
        });
    }

    public function test_brevo_http_error_surfaces_api_message(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response([
                'code' => 'unauthorized',
                'message' => 'Key not found',
            ], 401),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Brevo API returned HTTP 401: [unauthorized] Key not found');

        Mail::mailer('brevo')->raw('Test', function ($message): void {
            $message->to('teacher@example.com')->subject('Hi');
        });
    }
}

