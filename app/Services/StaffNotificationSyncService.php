<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\Lesson;
use App\Models\LessonActivityLog;
use App\Models\StaffNotification;
use App\Models\Student;
use App\Models\Submission;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates and persists per-user notifications for teachers and admins in
 * `staff_notifications`. Uses idempotent insertOrIgnore() keyed by
 * (user_id, notification_key) so read and dismissed/cleared notifications stay
 * cleared across page reloads and re-logins.
 */
class StaffNotificationSyncService
{
    public static function sanitizeText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return strtr($text, [
            'â€”'   => '—',
            'â€“'   => '–',
            'â€œ'   => '"',
            'â€'    => '"',
            'â€™'   => "'",
            'â€˜'   => "'",
            'â€¦'   => '…',
            'âœ…'  => '✅',
            'ðŸš«'  => '🚫',
            'ðŸ“š'  => '📚',
            'âœ ï¸' => '✏️',
            'ðŸš€'  => '🚀',
            'ðŸ—‘ï¸' => '🗑️',
            'ðŸ“‹'  => '📋',
            'ðŸ”’'  => '🔒',
            'ðŸ”„'  => '🔄',
        ]);
    }

    public function syncForTeacher(User $user): void
    {
        $teacher = Teacher::where('email', $user->email)->first();
        $assignedSubjectIds = $teacher
            ? $teacher->subjects()->pluck('subjects.id')
            : collect();

        $now = now();
        $rows = [];

        // 1. Student lesson views & downloads for teacher's lessons
        $lessonIds = Lesson::where('teacher_id', $user->id)
            ->when($assignedSubjectIds->isNotEmpty(), fn ($q) => $q->orWhereIn('subject_id', $assignedSubjectIds))
            ->pluck('id');

        if ($lessonIds->isNotEmpty()) {
            $lessonLogs = LessonActivityLog::with(['lesson.subject:id,name', 'student'])
                ->whereIn('lesson_id', $lessonIds)
                ->orderByDesc('updated_at')
                ->limit(80)
                ->get();

            foreach ($lessonLogs as $log) {
                if (! $log->lesson || ! $log->student) {
                    continue;
                }
                $rows[] = $this->buildLessonActivityRow(
                    $user->id,
                    $log->lesson,
                    $log->student,
                    $log->type,
                    $log->updated_at ?? $log->created_at ?? $now,
                    $now,
                );
            }
        }

        // 2. Activities, student submissions, due-soon & due-date non-answering alerts
        $activities = Activity::with(['subject.students', 'submissions.student'])
            ->where(function ($q) use ($user, $assignedSubjectIds) {
                $q->where('teacher_id', $user->id);
                if ($assignedSubjectIds->isNotEmpty()) {
                    $q->orWhereIn('subject_id', $assignedSubjectIds);
                }
            })
            ->where('status', '!=', 'Draft')
            ->orderByDesc('due_date')
            ->get();

        foreach ($activities as $activity) {
            $subject = $activity->subject;
            $subjectName = $subject?->name ?? 'a subject';

            foreach ($activity->submissions as $submission) {
                if (! in_array($submission->status, ['submitted', 'graded'], true)) {
                    continue;
                }
                $rows[] = $this->buildSubmissionRow($user->id, $activity, $submission, $now);
            }

            if (! $activity->due_date || ! $subject) {
                continue;
            }

            // Skip activities whose open window has not even started yet.
            $resolvedStatus = $activity->resolveStatus();
            if ($resolvedStatus === 'Scheduled') {
                continue;
            }

            $enrolled = $subject->students;
            if ($enrolled->isEmpty()) {
                continue;
            }

            $submittedUserIds = $activity->submissions
                ->filter(fn (Submission $s) => in_array($s->status, ['submitted', 'graded'], true))
                ->pluck('student_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $missingStudents = $enrolled
                ->filter(fn (Student $s) => ! in_array((int) $s->user_id, $submittedUserIds, true))
                ->values();

            if ($missingStudents->isEmpty()) {
                continue;
            }

            $dueAt = $this->activityDueAt($activity);
            $missingNames = $this->formatStudentNamesSummary($missingStudents);
            $pendingCount = $missingStudents->count();
            $dueLabel = $dueAt->format('M j, Y g:i A');

            if ($now->greaterThanOrEqualTo($dueAt)) {
                $verb = $pendingCount === 1 ? 'has' : 'have';
                $rows[] = [
                    'user_id'          => $user->id,
                    'notification_key' => "activity_unanswered_due:{$activity->id}:v{$dueAt->timestamp}",
                    'type'             => 'deadline',
                    'title'            => "Due Date Reached — Unanswered {$activity->type}: {$activity->title}",
                    'message'          => "{$missingNames} {$verb} not answered \"{$activity->title}\" ({$subjectName}). Due date ({$dueLabel}) has arrived.",
                    'target_type'      => 'task',
                    'target_id'        => $activity->subject_id,
                    'event_at'         => $dueAt,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            } elseif ($now->diffInMinutes($dueAt, false) <= 24 * 60) {
                $hoursLeft = max(1, (int) ceil($now->diffInMinutes($dueAt, false) / 60));
                $verb = $pendingCount === 1 ? 'has' : 'have';
                $rows[] = [
                    'user_id'          => $user->id,
                    'notification_key' => "activity_due_soon:{$activity->id}:v{$dueAt->timestamp}",
                    'type'             => 'deadline',
                    'title'            => "Due Soon: {$activity->title}",
                    'message'          => "{$missingNames} {$verb} not answered \"{$activity->title}\" ({$subjectName}). Due in {$hoursLeft}h ({$dueLabel}).",
                    'target_type'      => 'task',
                    'target_id'        => $activity->subject_id,
                    'event_at'         => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
        }

        // 3. Teacher's own activity logs (enrollments, lessons, tasks)
        $bellActionMap = [
            'enroll_student'   => ['title' => 'Student Enrolled',    'type' => 'enroll',   'target_type' => 'students'],
            'unenroll_student' => ['title' => 'Student Removed',     'type' => 'unenroll', 'target_type' => 'students'],
            'UPLOAD_LESSON'    => ['title' => 'Lesson Uploaded',     'type' => 'lesson',   'target_type' => 'lesson'],
            'EDIT_LESSON'      => ['title' => 'Lesson Edited',       'type' => 'lesson',   'target_type' => 'lesson'],
            'PUBLISH_LESSON'   => ['title' => 'Lesson Published',    'type' => 'lesson',   'target_type' => 'lesson'],
            'DELETE_LESSON'    => ['title' => 'Lesson Deleted',      'type' => 'lesson',   'target_type' => 'lesson'],
            'CREATE_ACTIVITY'  => ['title' => 'Task Created',        'type' => 'task',     'target_type' => 'task'],
            'EDIT_ACTIVITY'    => ['title' => 'Task Edited',         'type' => 'task',     'target_type' => 'task'],
            'CLOSE_ACTIVITY'   => ['title' => 'Task Closed',         'type' => 'task',     'target_type' => 'task'],
            'DELETE_ACTIVITY'  => ['title' => 'Task Deleted',        'type' => 'task',     'target_type' => 'task'],
            'TOGGLE_ACTIVITY'  => ['title' => 'Task Status Changed', 'type' => 'task',     'target_type' => 'task'],
        ];

        $ownLogs = ActivityLog::where('user_id', $user->id)
            ->whereIn('action', array_keys($bellActionMap))
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        foreach ($ownLogs as $log) {
            $meta = $bellActionMap[$log->action];
            $rows[] = [
                'user_id'          => $user->id,
                'notification_key' => "activity_log:{$log->id}",
                'type'             => $meta['type'],
                'title'            => $meta['title'],
                'message'          => self::sanitizeText($log->description),
                'target_type'      => $meta['target_type'],
                'target_id'        => null,
                'event_at'         => $log->created_at ?? $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        $this->insertRows($rows);
    }

    public function syncForAdmin(User $admin): void
    {
        $now = now();
        $rows = [];

        // 1. Pending teacher registration requests
        $pendingRegs = DB::table('teacher_registration_requests')
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        foreach ($pendingRegs as $reg) {
            $rows[] = $this->buildTeacherRegistrationRow($admin->id, $reg, $now);
        }

        // 2. Recent teacher accounts created (last 7 days)
        User::where('role', 'teacher')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (User $u) use (&$rows, $admin, $now) {
                $rows[] = [
                    'user_id'          => $admin->id,
                    'notification_key' => "admin_teacher_created:{$u->id}",
                    'type'             => 'teacher',
                    'title'            => 'New Teacher Account Created',
                    'message'          => "{$u->name} has been added as a teacher. Email: {$u->email}",
                    'target_type'      => 'teachers',
                    'target_id'        => null,
                    'event_at'         => $u->created_at ?? $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            });

        // 3. Recent student accounts created (last 7 days)
        User::where('role', 'student')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (User $u) use (&$rows, $admin, $now) {
                $rows[] = [
                    'user_id'          => $admin->id,
                    'notification_key' => "admin_student_created:{$u->id}",
                    'type'             => 'student',
                    'title'            => 'New Student Account Added',
                    'message'          => "{$u->name} has been enrolled as a student.",
                    'target_type'      => 'students',
                    'target_id'        => null,
                    'event_at'         => $u->created_at ?? $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            });

        // 4. Lessons uploaded/updated (last 7 days)
        Lesson::with('teacher:id,name')
            ->where('updated_at', '>=', $now->copy()->subDays(7))
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get()
            ->each(function (Lesson $l) use (&$rows, $admin, $now) {
                $teacherName = $l->teacher?->name ?? 'A teacher';
                $wasCreated = $l->created_at && $l->updated_at && $l->created_at->equalTo($l->updated_at);
                $action = $wasCreated ? 'uploaded' : 'updated';
                $stamp = ($l->updated_at ?? $now)->timestamp;
                $rows[] = [
                    'user_id'          => $admin->id,
                    'notification_key' => "admin_lesson:{$l->id}:{$stamp}",
                    'type'             => 'lesson',
                    'title'            => 'Lesson Material ' . ucfirst($action),
                    'message'          => "{$teacherName} {$action} \"{$l->title}\".",
                    'target_type'      => 'activity_logs',
                    'target_id'        => null,
                    'event_at'         => $l->updated_at ?? $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            });

        // 5. Recent quizzes / assignments & deadline alerts
        Activity::with(['subject.students', 'submissions'])
            ->whereIn('type', ['Quiz', 'Assignment'])
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->each(function (Activity $a) use (&$rows, $admin, $now) {
                $typeLabel = strtolower($a->type);
                $subjectName = $a->subject?->name ?? 'a subject';
                $rows[] = [
                    'user_id'          => $admin->id,
                    'notification_key' => "admin_activity:{$a->id}",
                    'type'             => $typeLabel,
                    'title'            => "New {$a->type} Posted",
                    'message'          => "A new {$typeLabel} \"{$a->title}\" was posted for {$subjectName}.",
                    'target_type'      => 'subjects',
                    'target_id'        => null,
                    'event_at'         => $a->created_at ?? $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];

                if ($a->due_date && $a->subject && $a->status !== 'Draft') {
                    $totalStudents = $a->subject->students->count();
                    $submittedCount = $a->submissions
                        ->filter(fn (Submission $s) => in_array($s->status, ['submitted', 'graded'], true))
                        ->count();
                    $pendingCount = max(0, $totalStudents - $submittedCount);
                    $dueAt = $this->activityDueAt($a);

                    if ($pendingCount > 0 && $dueAt->isFuture() && $now->diffInHours($dueAt, false) <= 48) {
                        $hoursLeft = max(1, (int) ceil($now->diffInMinutes($dueAt, false) / 60));
                        $rows[] = [
                            'user_id'          => $admin->id,
                            'notification_key' => "admin_deadline:{$a->id}:v{$dueAt->timestamp}",
                            'type'             => 'deadline',
                            'title'            => "Deadline Alert: {$a->title}",
                            'message'          => "{$pendingCount} student" . ($pendingCount > 1 ? 's have' : ' has') . " not answered \"{$a->title}\" for {$subjectName}. {$hoursLeft}h remaining.",
                            'target_type'      => 'subjects',
                            'target_id'        => null,
                            'event_at'         => $now,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ];
                    }
                }
            });

        $this->insertRows($rows);
    }

    public function dispatchLessonActivity(Lesson $lesson, Student $student, string $type): void
    {
        $lesson->loadMissing('subject:id,name');
        $teacherIds = $this->teacherUserIdsForTarget($lesson->teacher_id, $lesson->subject_id);
        if ($teacherIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($teacherIds as $teacherUserId) {
            $rows[] = $this->buildLessonActivityRow($teacherUserId, $lesson, $student, $type, $now, $now);
        }
        $this->insertRows($rows);

        // If this view/download happened again after an earlier one, keep the notification updated if not dismissed.
        $key = $this->lessonActivityKey($lesson->id, $student->id, $type);
        StaffNotification::whereIn('user_id', $teacherIds)
            ->where('notification_key', $key)
            ->whereNull('dismissed_at')
            ->update(['event_at' => $now, 'updated_at' => $now]);
    }

    public function dispatchSubmissionForTeacher(Submission $submission): void
    {
        $submission->loadMissing(['activity.subject', 'student']);
        $activity = $submission->activity;
        if (! $activity || ! in_array($submission->status, ['submitted', 'graded'], true)) {
            return;
        }

        $now = now();
        $teacherIds = $this->teacherUserIdsForTarget($activity->teacher_id, $activity->subject_id);
        $rows = $teacherIds
            ->map(fn (int $teacherUserId) => $this->buildSubmissionRow($teacherUserId, $activity, $submission, $now))
            ->all();

        $this->insertRows($rows);
    }

    public function dispatchLessonPublishedForTeacher(Lesson $lesson): void
    {
        $lesson->loadMissing('subject:id,name');
        $teacherIds = $this->teacherUserIdsForTarget($lesson->teacher_id, $lesson->subject_id);
        if ($teacherIds->isEmpty()) {
            return;
        }

        $now = now();
        $subjectName = $lesson->subject?->name ?? 'your subject';
        $rows = $teacherIds->map(fn (int $teacherUserId) => [
            'user_id'          => $teacherUserId,
            'notification_key' => "lesson_auto_published:{$lesson->id}",
            'type'             => 'lesson',
            'title'            => 'Scheduled Lesson Published',
            'message'          => "\"{$lesson->title}\" ({$subjectName}) is now live for your students.",
            'target_type'      => 'lesson',
            'target_id'        => $lesson->subject_id,
            'event_at'         => $lesson->published_at ?? $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ])->all();

        $this->insertRows($rows);
    }

    public function dispatchTeacherRegistrationRequest(int $registrationId): void
    {
        $reg = DB::table('teacher_registration_requests')->where('id', $registrationId)->first();
        if (! $reg || $reg->status !== 'pending') {
            return;
        }

        $adminIds = $this->activeAdminUserIds();
        if ($adminIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $adminIds
            ->map(fn (int $adminId) => $this->buildTeacherRegistrationRow($adminId, $reg, $now))
            ->all();

        $this->insertRows($rows);
    }

    public function clearTeacherRegistrationRequest(int $registrationId): void
    {
        StaffNotification::where('notification_key', "teacher_reg_pending:{$registrationId}")
            ->delete();
    }

    public function dispatchSettingChanged(string $key, bool $newValue, ?User $actor = null): void
    {
        $metaMap = [
            'teacher_registration' => [
                'on_title'    => 'Teacher Registration Enabled',
                'on_message'  => 'Teacher self-registration is now ON. New teacher account requests will appear here for approval.',
                'off_title'   => 'Teacher Registration Disabled',
                'off_message' => 'Teacher self-registration has been turned OFF in Settings.',
                'target_type' => 'teacher_registrations',
            ],
            'student_self_enrollment' => [
                'on_title'    => 'Student Self-Enrollment Enabled',
                'on_message'  => 'Student self-enrollment is now ON in Settings.',
                'off_title'   => 'Student Self-Enrollment Disabled',
                'off_message' => 'Student self-enrollment has been turned OFF in Settings.',
                'target_type' => 'settings',
            ],
            'maintenance_mode' => [
                'on_title'    => 'Maintenance Mode Enabled',
                'on_message'  => 'Maintenance mode is now ON. Non-admin portals are temporarily restricted.',
                'off_title'   => 'Maintenance Mode Disabled',
                'off_message' => 'Maintenance mode is now OFF. Teacher and student access is restored.',
                'target_type' => 'settings',
            ],
        ];

        if (! isset($metaMap[$key])) {
            return;
        }

        $meta = $metaMap[$key];
        $adminIds = $this->activeAdminUserIds();
        if ($actor && $actor->isAdmin() && ! $adminIds->contains($actor->id)) {
            $adminIds->push($actor->id);
        }
        if ($adminIds->isEmpty()) {
            return;
        }

        $now = now();
        $stateWord = $newValue ? 'on' : 'off';
        $uniqueStamp = $now->format('YmdHisv');
        $rows = $adminIds->map(fn (int $adminId) => [
            'user_id'          => $adminId,
            'notification_key' => "setting_toggle:{$key}:{$stateWord}:{$uniqueStamp}",
            'type'             => 'setting',
            'title'            => $newValue ? $meta['on_title'] : $meta['off_title'],
            'message'          => $newValue ? $meta['on_message'] : $meta['off_message'],
            'target_type'      => $meta['target_type'],
            'target_id'        => null,
            'event_at'         => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ])->all();

        $this->insertRows($rows);
    }

    public function removeForLesson(int $lessonId): void
    {
        StaffNotification::where(function ($q) use ($lessonId) {
            $q->where('notification_key', 'like', "lesson_view:{$lessonId}:%")
                ->orWhere('notification_key', 'like', "lesson_download:{$lessonId}:%")
                ->orWhere('notification_key', "lesson_auto_published:{$lessonId}")
                ->orWhere('notification_key', 'like', "admin_lesson:{$lessonId}:%");
        })->delete();
    }

    public function removeForActivity(int $activityId): void
    {
        StaffNotification::where(function ($q) use ($activityId) {
            $q->where('notification_key', 'like', "activity_unanswered_due:{$activityId}:%")
                ->orWhere('notification_key', 'like', "activity_due_soon:{$activityId}:%")
                ->orWhere('notification_key', "admin_activity:{$activityId}")
                ->orWhere('notification_key', 'like', "admin_deadline:{$activityId}:%");
        })->delete();
    }

    private function buildLessonActivityRow(
        int $teacherUserId,
        Lesson $lesson,
        Student $student,
        string $type,
        Carbon $eventAt,
        Carbon $now,
    ): array {
        $isDownload = $type === 'download';
        $studentName = trim((string) ($student->full_name ?: trim("{$student->first_name} {$student->last_name}")));
        $subjectName = $lesson->subject?->name ?? 'your subject';

        return [
            'user_id'          => $teacherUserId,
            'notification_key' => $this->lessonActivityKey($lesson->id, $student->id, $type),
            'type'             => $isDownload ? 'lesson_download' : 'lesson_view',
            'title'            => $isDownload ? 'Lesson Downloaded' : 'Lesson Viewed',
            'message'          => "{$studentName} " . ($isDownload ? 'downloaded' : 'viewed') . " lesson \"{$lesson->title}\" ({$subjectName}).",
            'target_type'      => 'lesson',
            'target_id'        => $lesson->subject_id,
            'event_at'         => $eventAt,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }

    private function lessonActivityKey(int $lessonId, int $studentId, string $type): string
    {
        return ($type === 'download' ? 'lesson_download' : 'lesson_view') . ":{$lessonId}:{$studentId}";
    }

    private function buildSubmissionRow(int $teacherUserId, Activity $activity, Submission $submission, Carbon $now): array
    {
        $studentName = $submission->student?->full_name
            ?? User::where('id', $submission->student_id)->value('name')
            ?? 'A student';
        $subjectName = $activity->subject?->name ?? 'your subject';
        $verb = $activity->type === 'Quiz' ? 'completed quiz' : 'submitted';
        $lateNote = $submission->is_late ? ' (Late)' : '';
        $gradingNote = $submission->needs_manual_review ? ' — Needs manual grading.' : '.';

        return [
            'user_id'          => $teacherUserId,
            'notification_key' => "submission:{$submission->id}",
            'type'             => strtolower($activity->type),
            'title'            => "{$activity->type} Submitted: {$activity->title}",
            'message'          => "{$studentName} {$verb} \"{$activity->title}\" ({$subjectName}){$lateNote}{$gradingNote}",
            'target_type'      => 'task',
            'target_id'        => $activity->subject_id,
            'event_at'         => $submission->submitted_at ?? $submission->updated_at ?? $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }

    private function buildTeacherRegistrationRow(int $adminUserId, object $reg, Carbon $now): array
    {
        $name = trim(implode(' ', array_filter([
            $reg->first_name ?? null,
            $reg->middle_name ?? null,
            $reg->last_name ?? null,
            $reg->suffix ?? null,
        ])));
        $gradeSuffix = ! empty($reg->grade_level) ? " ({$reg->grade_level})" : '';

        return [
            'user_id'          => $adminUserId,
            'notification_key' => "teacher_reg_pending:{$reg->id}",
            'type'             => 'teacher_registration',
            'title'            => 'New Teacher Registration Request',
            'message'          => "{$name} ({$reg->email}){$gradeSuffix} applied for a teacher account and is waiting for approval.",
            'target_type'      => 'teacher_registrations',
            'target_id'        => (int) $reg->id,
            'event_at'         => $reg->created_at ? Carbon::parse($reg->created_at) : $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];
    }

    private function activityDueAt(Activity $activity): Carbon
    {
        return Carbon::parse($activity->due_date->format('Y-m-d') . ' ' . ($activity->due_time ?: '23:59:59'));
    }

    private function formatStudentNamesSummary(Collection $students): string
    {
        $names = $students
            ->take(3)
            ->map(fn (Student $s) => trim($s->full_name ?: "{$s->first_name} {$s->last_name}"))
            ->filter()
            ->values();

        if ($names->isEmpty()) {
            return $students->count() . ' student' . ($students->count() > 1 ? 's' : '');
        }

        $remaining = $students->count() - $names->count();
        $list = $names->implode(', ');

        return $remaining > 0 ? "{$list} and {$remaining} more" : $list;
    }

    private function teacherUserIdsForTarget(?int $ownerUserId, ?int $subjectId): Collection
    {
        $ids = collect($ownerUserId ? [(int) $ownerUserId] : []);
        if ($subjectId) {
            $assignedEmails = DB::table('subject_teacher')
                ->join('teachers', 'teachers.id', '=', 'subject_teacher.teacher_id')
                ->where('subject_teacher.subject_id', $subjectId)
                ->pluck('teachers.email');
            if ($assignedEmails->isNotEmpty()) {
                $ids = $ids->merge(User::where('role', 'teacher')->whereIn('email', $assignedEmails)->pluck('id'));
            }
        }

        return $ids->filter(fn ($id) => $id > 0)->unique()->values();
    }

    private function activeAdminUserIds(): Collection
    {
        return User::where('role', 'admin')
            ->whereNull('archived_at')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    private function insertRows(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('staff_notifications')->insertOrIgnore($chunk);
        }
    }
}
