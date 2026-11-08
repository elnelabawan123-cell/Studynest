<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminAlertMailer
{
    /**
     * Send a configured system alert to every active administrator address.
     *
     * @return array{status: string, delivered: int}
     */
    public function send(string $subject, string $body): array
    {
        if (!AdminSetting::get('email_notifications', true)) {
            return ['status' => 'disabled', 'delivered' => 0];
        }

        try {
            $recipients = User::query()
                ->where('role', 'admin')
                ->whereNull('archived_at')
                ->whereNotNull('email')
                ->pluck('email')
                ->filter()
                ->unique()
                ->values();
        } catch (Throwable $exception) {
            Log::error("Unable to load administrator addresses for an email alert: {$exception->getMessage()}");
            return ['status' => 'failed', 'delivered' => 0];
        }

        if ($recipients->isEmpty()) {
            Log::warning('Admin alert was not sent because no administrator email address is configured.');
            return ['status' => 'failed', 'delivered' => 0];
        }

        $delivered = 0;
        foreach ($recipients as $recipient) {
            try {
                Mail::raw($body, function ($message) use ($recipient, $subject): void {
                    $message->to($recipient)->subject($subject);
                });
                $delivered++;
            } catch (Throwable $exception) {
                Log::error("Failed to send admin alert to {$recipient}: {$exception->getMessage()}");
            }
        }

        return [
            'status' => $delivered === $recipients->count() ? 'sent' : 'failed',
            'delivered' => $delivered,
        ];
    }
}
