<?php

namespace App\Events;

use App\Models\Submission;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasts on the activity's private channel so the teacher dashboard
 * updates without a refresh.
 *
 * Callers guard this behind config('broadcasting.default') !== 'null' so it
 * is a safe no-op in environments that haven't set up Reverb/Pusher yet —
 * see routes/channels_additions.php and the Flutter realtime_service.dart
 * for the corresponding setup (which currently ships with a polling
 * fallback so live updates work even without broadcasting configured).
 */
class SubmissionProgressUpdated implements ShouldBroadcastNow
{
    use InteractsWithSockets, SerializesModels;

    public function __construct(public Submission $submission)
    {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("activity.{$this->submission->activity_id}")];
    }

    public function broadcastAs(): string
    {
        return 'submission.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'studentId' => $this->submission->student_id,
            'status' => $this->submission->displayStatus(),
            'isLate' => (bool) $this->submission->is_late,
            'completionPercentage' => $this->submission->completion_percentage,
            'totalScore' => $this->submission->total_score,
            'needsManualReview' => (bool) $this->submission->needs_manual_review,
        ];
    }
}
