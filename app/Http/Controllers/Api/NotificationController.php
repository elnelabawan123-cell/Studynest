<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\Activity;
use App\Models\Activitylog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class NotificationController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user    = $request->user();
        $teacher = Teacher::where('email', $user->email)->first();

        if (! $teacher) {
            return $this->success([]);
        }

        $subjectIds = $teacher->subjects()->pluck('subjects.id');

        if ($subjectIds->isEmpty()) {
            // No subjects yet, but still show the teacher's own activity log notifications
            return $this->success($this->buildOwnActivityNotifications($user)->values());
        }

        // ✅ Eager-load subject (with its students) and submissions
        $activities = Activity::whereIn('subject_id', $subjectIds)
            ->whereIn('type', ['Quiz', 'Assignment'])
            ->with(['subject.students', 'submissions'])
            ->orderByDesc('due_date')
            ->get();

        $notifications = collect();
        $now           = Carbon::now();
        $id            = 0;

        foreach ($activities as $activity) {
            $subject = $activity->subject;

            $totalStudents  = $subject ? $subject->students->count() : 0;
            $submittedCount = $activity->submissions->count();
            $pendingCount   = max(0, $totalStudents - $submittedCount);

            $dueDate      = Carbon::parse($activity->due_date);
            $deadlineNear = $dueDate->isFuture() && $dueDate->diffInHours($now) <= 48;
            $isPast       = $dueDate->isPast();
            $typeLabel    = $activity->type;
            $action       = $typeLabel === 'Quiz' ? 'took' : 'submitted';
            $nounSingular = $typeLabel === 'Quiz' ? 'Quiz' : 'Assignment';

            // ── Students who already submitted ──
            if ($submittedCount > 0) {
                $notifications->push([
                    'id'         => ++$id,
                    'title'      => "{$submittedCount} student" . ($submittedCount > 1 ? 's' : '') . " already {$action} {$activity->title}",
                    'message'    => "{$submittedCount} out of {$totalStudents} enrolled students have {$action} \"{$activity->title}\".",
                    'type'       => strtolower($typeLabel),
                    'is_read'    => false,
                    'created_at' => $activity->updated_at->toIso8601String(),
                ]);
            }

            // ── Deadline near + pending students ──
            if ($deadlineNear && $pendingCount > 0 && $subject) {
                $hoursLeft = max(0, (int) $now->diffInHours($dueDate, false));

                $submittedStudentIds = $activity->submissions->pluck('student_id');
                $pendingStudents     = $subject->students
                    ->whereNotIn('user_id', $submittedStudentIds)
                    ->take(3);

                foreach ($pendingStudents as $student) {
                    $name = trim("{$student->first_name} {$student->last_name}");
                    $notifications->push([
                        'id'         => ++$id,
                        'title'      => "Deadline Alert: {$activity->title}",
                        'message'    => "{$name} has not {$action} \"{$activity->title}\". Deadline is in {$hoursLeft}h.",
                        'type'       => 'deadline',
                        'is_read'    => false,
                        'created_at' => now()->toIso8601String(),
                    ]);
                }

                if ($pendingCount > 3) {
                    $remaining = $pendingCount - 3;
                    $notifications->push([
                        'id'         => ++$id,
                        'title'      => "Deadline Alert: {$activity->title}",
                        'message'    => "and {$remaining} more student" . ($remaining > 1 ? 's have' : ' has') . " not {$action} yet. {$hoursLeft}h left.",
                        'type'       => 'deadline',
                        'is_read'    => false,
                        'created_at' => now()->toIso8601String(),
                    ]);
                }
            }

            // ── Activity closed, final tally ──
            if ($isPast && $submittedCount > 0) {
                $notifications->push([
                    'id'         => ++$id,
                    'title'      => "{$nounSingular} Closed: {$activity->title}",
                    'message'    => "Final tally: {$submittedCount}/{$totalStudents} students {$action}. " . ($pendingCount > 0 ? "{$pendingCount} did not {$action}." : "All students completed it!"),
                    'type'       => strtolower($typeLabel),
                    'is_read'    => true,
                    'created_at' => $dueDate->toIso8601String(),
                ]);
            }
        }

        // ── Teacher's own activity log notifications (enroll, lessons, tasks) ──
        $ownActivityNotifs = $this->buildOwnActivityNotifications($user);
        foreach ($ownActivityNotifs as $n) {
            $n['id'] = ++$id;
            $notifications->push($n);
        }

        // Multi-key sort: unread first, then newest
        $sorted = $notifications
            ->sortBy([
                ['is_read',    'asc'],
                ['created_at', 'desc'],
            ])
            ->values();

        return $this->success($sorted);
    }

    /**
     * Build notifications from the teacher's own Activitylog rows.
     * Covers student enrollment, lesson uploads/edits/publishes/deletes,
     * and task (quiz/assignment) creation/edits/closures/deletes.
     */
    private function buildOwnActivityNotifications($user)
    {
        // Action → { title shown in bell, type used for icon/color on frontend }
        $bellActionMap = [
            // Student enrollment (module: teacher_students)
            'enroll_student'   => ['title' => '✅ Student Enrolled',  'type' => 'enroll'],
            'unenroll_student' => ['title' => '🚫 Student Removed',   'type' => 'unenroll'],

            // Lessons (module: Lessons)
            'UPLOAD_LESSON'    => ['title' => '📚 Lesson Uploaded',   'type' => 'lesson'],
            'EDIT_LESSON'      => ['title' => '✏️ Lesson Edited',     'type' => 'lesson'],
            'PUBLISH_LESSON'   => ['title' => '🚀 Lesson Published',  'type' => 'lesson'],
            'DELETE_LESSON'    => ['title' => '🗑️ Lesson Deleted',    'type' => 'lesson'],

            // Activities / Tasks (module: Activities)
            'CREATE_ACTIVITY'  => ['title' => '📋 Task Created',         'type' => 'task'],
            'EDIT_ACTIVITY'    => ['title' => '✏️ Task Edited',          'type' => 'task'],
            'CLOSE_ACTIVITY'   => ['title' => '🔒 Task Closed',          'type' => 'task'],
            'DELETE_ACTIVITY'  => ['title' => '🗑️ Task Deleted',         'type' => 'task'],
            'TOGGLE_ACTIVITY'  => ['title' => '🔄 Task Status Changed',  'type' => 'task'],
        ];

        $logs = Activitylog::where('user_id', $user->id)
            ->whereIn('action', array_keys($bellActionMap))
            ->orderByDesc('created_at')
            ->take(20)
            ->get();

        return $logs->map(function ($log) use ($bellActionMap) {
            $meta = $bellActionMap[$log->action];
            return [
                'id'         => 0, // overwritten by caller
                'title'      => $meta['title'],
                'message'    => $log->description,
                'type'       => $meta['type'],
                'is_read'    => $log->created_at->lt(Carbon::now()->subHours(1)),
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return $this->success(null, 'All notifications marked as read.');
    }
}