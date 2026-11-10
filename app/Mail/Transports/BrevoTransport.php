<?php

namespace App\Mail\Transports;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

/**
 * Sends Laravel mail messages through Brevo's Transactional Email HTTP API (port 443).
 *
 * Needed on cloud hosts such as Railway Free/Trial/Hobby plans that block outbound SMTP.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://api.brevo.com/v3/smtp/email',
        private readonly int $timeout = 10,
    ) {
        parent::__construct();
    }

    /**
     * {@inheritDoc}
     */
    protected function doSend(SentMessage $message): void
    {
        if (trim($this->apiKey) === '') {
            throw new TransportException('BREVO_API_KEY is not configured.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $fromAddress = $email->getFrom()[0] ?? $envelope->getSender();
        if (! $fromAddress instanceof Address || trim($fromAddress->getAddress()) === '') {
            throw new TransportException('A verified sender email address is required for Brevo.');
        }

        $payload = [
            'sender' => $this->formatAddress($fromAddress),
            'to' => $this->formatAddresses($this->getToRecipients($email, $envelope)),
            'subject' => (string) ($email->getSubject() ?? ''),
        ];

        $cc = $this->formatAddresses($email->getCc());
        if ($cc !== []) {
            $payload['cc'] = $cc;
        }

        $bcc = $this->formatAddresses($email->getBcc());
        if ($bcc !== []) {
            $payload['bcc'] = $bcc;
        }

        $replyTo = $email->getReplyTo();
        if ($replyTo !== []) {
            $payload['replyTo'] = $this->formatAddress($replyTo[0]);
        }

        $html = $email->getHtmlBody();
        if (is_string($html) && $html !== '') {
            $payload['htmlContent'] = $html;
        }

        $text = $email->getTextBody();
        if (is_string($text) && $text !== '') {
            $payload['textContent'] = $text;
        }

        // Brevo requires at least one of htmlContent or textContent.
        if (! isset($payload['htmlContent']) && ! isset($payload['textContent'])) {
            $payload['textContent'] = '';
        }

        $attachments = $this->formatAttachments($email);
        if ($attachments !== []) {
            $payload['attachment'] = $attachments;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(max(1, $this->timeout))
                ->withHeaders([
                    'api-key' => $this->apiKey,
                ])
                ->post($this->endpoint, $payload);
        } catch (Throwable $exception) {
            throw new TransportException(
                sprintf('Request to Brevo API failed: %s', $exception->getMessage()),
                is_int($exception->getCode()) ? $exception->getCode() : 0,
                $exception
            );
        }

        if (! $response->successful()) {
            $errorData = $response->json();
            $detail = is_array($errorData)
                ? trim(implode(' ', array_filter([
                    isset($errorData['code']) ? "[{$errorData['code']}]" : null,
                    $errorData['message'] ?? null,
                ])))
                : trim($response->body());

            throw new TransportException(
                sprintf(
                    'Brevo API returned HTTP %d%s',
                    $response->status(),
                    $detail !== '' ? ": {$detail}" : '.'
                ),
                $response->status()
            );
        }

        $messageId = $response->json('messageId');
        if (is_string($messageId) && $messageId !== '') {
            $email->getHeaders()->addTextHeader('X-Brevo-Message-ID', $messageId);
        }
    }

    /**
     * Get primary recipients excluding CC and BCC addresses.
     *
     * @return array<int, Address>
     */
    private function getToRecipients(Email $email, Envelope $envelope): array
    {
        $ccAndBcc = array_merge($email->getCc(), $email->getBcc());

        return array_values(array_filter(
            $envelope->getRecipients(),
            fn (Address $address): bool => ! in_array($address, $ccAndBcc, true)
        ));
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    private function formatAddresses(array $addresses): array
    {
        return array_values(array_map(
            fn (Address $address): array => $this->formatAddress($address),
            $addresses
        ));
    }

    /**
     * @return array{email: string, name?: string}
     */
    private function formatAddress(Address $address): array
    {
        $formatted = ['email' => $address->getAddress()];
        $name = trim($address->getName());
        if ($name !== '') {
            $formatted['name'] = $name;
        }

        return $formatted;
    }

    /**
     * @return array<int, array{content: string, name: string}>
     */
    private function formatAttachments(Email $email): array
    {
        $items = [];
        foreach ($email->getAttachments() as $index => $attachment) {
            $filename = $attachment->getFilename() ?: sprintf('attachment-%d', $index + 1);
            $items[] = [
                'content' => base64_encode($attachment->getBody()),
                'name' => $filename,
            ];
        }

        return $items;
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}

