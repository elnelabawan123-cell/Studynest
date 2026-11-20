<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lesson;
use App\Models\StudentNotification;
use App\Models\Submission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates and persists per-student notifications for published lessons and
 * open/due activities (quizzes, assignments, activities).
 *
 * Idempotency is guaranteed by the (student_user_id, notification_key) unique
 * constraint paired with insertOrIgnore(): if a student has already read or
 * dismissed/cleared a notification, re-syncing never resurrects it.
 */
class StudentNotificationSyncService
{
    public function dispatchForLesson(Lesson $lesson): void
    {
        if ($lesson->status !== 'published') {
            return;
        }

        $lesson->loadMissing('subject:id,name');
        $userIds = $this->enrolledStudentUserIdsForSubject((int) $lesson->subject_id);
        if ($userIds->isEmpty()) {
            return;
        }

        $now = now();
        $eventAt = $lesson->published_at ?? $lesson->created_at ?? $now;
        $payload = $this->lessonPayload($lesson, $eventAt);

        $rows = $userIds->map(fn (int $userId) => array_merge($payload, [
            'student_user_id' => $userId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]))->all();

        $this->insertRows($rows);
    }

    public function dispatchForActivity(Activity $activity): void
    {
        if ($activity->resolveStatus() !== 'Open') {
            return;
        }

        $activity->loadMissing('subject:id,name');
        $userIds = $this->enrolledStudentUserIdsForSubject((int) $activity->subject_id);
        if ($userIds->isEmpty()) {
            return;
        }

        $now = now();
        $eventAt = $this->activityOpenAt($activity) ?? $activity->created_at ?? $now;
        $payload = $this->activityAvailablePayload($activity, $eventAt);

        $rows = $userIds->map(fn (int $userId) => array_merge($payload, [
            'student_user_id' => $userId,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]))->all();

        $this->insertRows($rows);
    }

    public function removeForTarget(string $targetType, int $targetId): void
    {
        StudentNotification::where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->delete();
    }

    public function syncForStudent(User $user): void
    {
        $studentProfile = $user->studentProfile;
        if (! $studentProfile) {
            return;
        }

        $subjectIds = DB::table('subject_student')
            ->where('student_id', $studentProfile->id)
            ->pluck('subject_id');

        if ($subjectIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];

        $lessons = Lesson::with('subject:id,name')
            ->whereIn('subject_id', $subjectIds)
            ->where('status', 'published')
            ->get();

        foreach ($lessons as $lesson) {
            $eventAt = $lesson->published_at ?? $lesson->created_at ?? $now;
            $rows[] = array_merge($this->lessonPayload($lesson, $eventAt), [
                'student_user_id' => $user->id,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        $activities = Activity::with('subject:id,name')
            ->whereIn('subject_id', $subjectIds)
            ->where('status', '!=', 'Draft')
            ->get();

        $submissions = Submission::where('student_id', $user->id)
            ->whereIn('activity_id', $activities->pluck('id'))
            ->get()
            ->keyBy('activity_id');

        foreach ($activities as $activity) {
            $resolvedStatus = $activity->resolveStatus();
            if ($resolvedStatus !== 'Open') {
                continue;
            }

            $openEventAt = $this->activityOpenAt($activity) ?? $activity->created_at ?? $now;
            $rows[] = array_merge($this->activityAvailablePayload($activity, $openEventAt), [
                'student_user_id' => $user->id,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

            $submission = $submissions->get($activity->id);
            $displayStatus = $submission?->displayStatus() ?? 'Not Started';
            if (in_array($displayStatus, ['Submitted', 'Late Submission', 'Graded'], true)) {
                continue;
            }

            $dueAt = $this->activityDueAt($activity);
            if ($dueAt !== null && $dueAt->lte($now)) {
                $rows[] = array_merge($this->activityDuePayload($activity, $dueAt, $now), [
                    'student_user_id' => $user->id,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);
            }
        }

        $this->insertRows($rows);
    }

    private function enrolledStudentUserIdsForSubject(int $subjectId): Collection
    {
        return DB::table('subject_student')
            ->join('students', 'students.id', '=', 'subject_student.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->where('subject_student.subject_id', $subjectId)
            ->where('users.role', 'student')
            ->whereNull('users.archived_at')
            ->pluck('students.user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    private function insertRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            StudentNotification::insertOrIgnore($chunk);
        }
    }

    private function lessonPayload(Lesson $lesson, Carbon $eventAt): array
    {
        $subjectName = $lesson->subject?->name ?? 'Subject';

        return [
            'notification_key' => "lesson-{$lesson->id}-published",
            'type'             => 'lesson',
            'title'            => 'New lesson uploaded',
            'body'             => "{$subjectName}: \"{$lesson->title}\" is ready to view.",
            'subject_name'     => $subjectName,
            'target_type'      => 'lesson',
            'target_id'        => $lesson->id,
            'event_at'         => $eventAt,
            'read_at'          => null,
            'dismissed_at'     => null,
        ];
    }

    private function activityAvailablePayload(Activity $activity, Carbon $eventAt): array
    {
        $subjectName = $activity->subject?->name ?? 'Subject';
        $typeLower = strtolower((string) $activity->type);
        $category = match ($typeLower) {
            'quiz'       => 'quiz',
            'assignment' => 'assignment',
            'activity'   => 'activity',
            default      => 'assignment',
        };

        return [
            'notification_key' => "activity-{$activity->id}-new",
            'type'             => $category,
            'title'            => "New {$category} available",
            'body'             => "{$subjectName}: \"{$activity->title}\" is now open.",
            'subject_name'     => $subjectName,
            'target_type'      => 'activity',
            'target_id'        => $activity->id,
            'event_at'         => $eventAt,
            'read_at'          => null,
            'dismissed_at'     => null,
        ];
    }

    private function activityDuePayload(Activity $activity, Carbon $dueAt, Carbon $now): array
    {
        $subjectName = $activity->subject?->name ?? 'Subject';
        $isDueNow = $dueAt->diffInHours($now, false) <= 2;

        return [
            'notification_key' => "activity-{$activity->id}-due",
            'type'             => 'reminder',
            'title'            => $isDueNow ? 'Task due now' : 'Task overdue',
            'body'             => $isDueNow
                ? "{$subjectName}: \"{$activity->title}\" is due now."
                : "{$subjectName}: \"{$activity->title}\" is past due.",
            'subject_name'     => $subjectName,
            'target_type'      => 'activity',
            'target_id'        => $activity->id,
            'event_at'         => $dueAt,
            'read_at'          => null,
            'dismissed_at'     => null,
        ];
    }

    private function activityOpenAt(Activity $activity): ?Carbon
    {
        if (! $activity->open_date) {
            return null;
        }

        $date = $activity->open_date->format('Y-m-d');
        $time = $activity->open_time ?: '00:00:00';

        return Carbon::parse("{$date} {$time}");
    }

    private function activityDueAt(Activity $activity): ?Carbon
    {
        if (! $activity->due_date) {
            return null;
        }

        $date = $activity->due_date->format('Y-m-d');
        $time = $activity->due_time ?: '23:59:59';

        return Carbon::parse("{$date} {$time}");
    }
}
