<?php

namespace App\Http\Resources;

use App\Support\StorageFileUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shape mirrors the React `Activity` interface in Activitypage.tsx:
 *   { id, type, title, description, subject, grade, status,
 *     dueDate, dueTime, openDate, openTime, totalPoints,
 *     submissions, totalStudents, needsGradingCount,
 *     allowPhotoAnswer, allowTextAnswer, quizQuestions?, createdAt, timeLimit? }
 */
class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'type'             => $this->type,
            'title'            => $this->title,
            'description'     => $this->description ?? '',

            'subject'          => $this->subject?->name ?? '',
            'grade'            => $this->subject?->grade_level ?? '',
            'subject_id'       => $this->subject_id,

            // Effective status (auto-resolves Scheduled/Closed against current time)
            'status'           => $this->resolveStatus(),
            // Display-only: adds 'Overdue' on top of 'Open' once due_date
            // has passed. Never a gate — submissions are still accepted.
            'displayStatus'    => $this->displayStatus,
            'isClosed'         => $this->status === 'Closed',
            'closedAt'         => optional($this->closed_at)->toIso8601String(),
            'closedBy'         => $this->closedBy?->name,

            'openDate'         => optional($this->open_date)->format('Y-m-d'),
            'openTime'         => $this->formatTime($this->open_time),
            'dueDate'          => optional($this->due_date)->format('Y-m-d'),
            'dueTime'          => $this->formatTime($this->due_time),

            'totalPoints'      => (int) $this->total_points,
            'allowPhotoAnswer' => (bool) $this->allow_photo_answer,
            'allowTextAnswer'  => (bool) $this->allow_text_answer,
            'attachmentPath'   => $this->attachment_path,
            'attachmentUrl'    => StorageFileUrl::forPath($this->attachment_path),
            'attachmentType'   => $this->attachment_type,
            'timeLimit'        => $this->time_limit,

            // Real, submission-table-backed counts (see StudentSubmissionController
            // and the Submission model added for the grading feature).
            'submissions'        => $this->submissions_count,
            'totalStudents'      => $this->total_students,
            'needsGradingCount'  => $this->submissions()->where('needs_manual_review', true)->count(),

            'quizQuestions'    => $this->when(
                $this->relationLoaded('quizQuestions') && $this->quizQuestions->isNotEmpty(),
                fn () => $this->quizQuestions->map(fn ($q) => [
                    'id'             => $q->id,
                    'question'       => $q->question,
                    'answerType'     => $q->answer_type,
                    'checkingMode'   => $q->checking_mode,
                    'choices'        => $q->choices,
                    'correctAnswer'  => $q->correct_answer,
                    'points'         => (int) $q->points,
                    'attachmentPath' => $q->attachment_path,
                    'attachmentUrl'  => StorageFileUrl::forPath($q->attachment_path),
                    'attachmentType' => $q->attachment_type,
                ])->values(),
            ),

            'createdAt'        => optional($this->created_at)->format('M j, Y'),
        ];
    }

    /**
     * Time values may come back from MySQL as 'HH:MM:SS' strings.
     * Frontend expects 'HH:MM'.
     */
    private function formatTime($value): ?string
    {
        if (!$value) return null;
        return substr((string) $value, 0, 5);
    }
}