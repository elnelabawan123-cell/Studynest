<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Activity;
use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminnotificationController extends BaseApiController
{
    /**
     * GET /api/admin/notifications
     * Generates real system-wide notifications for the admin dashboard bell.
     */
    public function index(): JsonResponse
    {
        $notifications = collect();
        $now = Carbon::now();
        $id  = 0;

        try {
            // ── 1. New teacher accounts (created in the last 7 days) ─────────
        User::where('role', 'teacher')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->each(function ($u) use (&$notifications, &$id, $now) {
                $notifications->push([
                    'id'         => ++$id,
                    'title'      => 'New Teacher Account Created',
                    'message'    => "{$u->name} has been added as a teacher. Email: {$u->email}",
                    'type'       => 'teacher',
                    'is_read'    => false,
                    'created_at' => $u->created_at->toIso8601String(),
                ]);
            });

        // ── 2. New student accounts (created in the last 7 days) ─────────
        User::where('role', 'student')
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->orderByDesc('created_at')
            ->take(5)
            ->get()
            ->each(function ($u) use (&$notifications, &$id) {
                $notifications->push([
                    'id'         => ++$id,
                    'title'      => 'New Student Account Added',
                    'message'    => "{$u->name} has been enrolled as a student.",
                    'type'       => 'student',
                    'is_read'    => false,
                    'created_at' => $u->created_at->toIso8601String(),
                ]);
            });

        // ── 3. New / updated lessons (last 7 days) ────────────────────────
        if (class_exists(Lesson::class)) {
            Lesson::with('teacher:id,name')
                ->where('updated_at', '>=', $now->copy()->subDays(7))
                ->orderByDesc('updated_at')
                ->take(5)
                ->get()
                ->each(function ($l) use (&$notifications, &$id) {
                    $teacherName = $l->teacher?->name ?? 'A teacher';
                    $action      = $l->wasRecentlyCreated ? 'uploaded' : 'updated';
                    $notifications->push([
                        'id'         => ++$id,
                        'title'      => 'Lesson Material ' . ucfirst($action),
                        'message'    => "{$teacherName} {$action} \"{$l->title}\".",
                        'type'       => 'lesson',
                        'is_read'    => true,
                        'created_at' => $l->updated_at->toIso8601String(),
                    ]);
                });
        }

        // ── 4 & 5. New quizzes / assignments (last 7 days) ────────────────
        if (class_exists(Activity::class)) {
            Activity::with(['subject:id,name'])
                ->whereIn('type', ['Quiz', 'Assignment'])
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->orderByDesc('created_at')
                ->take(6)
                ->get()
                ->each(function ($a) use (&$notifications, &$id) {
                    $typeLabel = $a->type === 'Quiz' ? 'quiz' : 'assignment';
                    $subjectName = $a->subject?->name ?? 'a subject';
                    $notifications->push([
                        'id'         => ++$id,
                        'title'      => "New {$a->type} Posted",
                        'message'    => "A new {$typeLabel} \"{$a->title}\" was posted for {$subjectName}.",
                        'type'       => $typeLabel,
                        'is_read'    => true,
                        'created_at' => $a->created_at->toIso8601String(),
                    ]);
                });

            // ── 6 & 7. Deadline alerts (activities due within 48h, unsubmitted) ──
            Activity::with('subject.students')
                ->whereIn('type', ['Quiz', 'Assignment'])
                ->whereBetween('due_date', [$now, $now->copy()->addHours(48)])
                ->get()
                ->each(function ($a) use (&$notifications, &$id, $now) {
                    $totalStudents  = $a->subject?->students()->count() ?? 0;
                    $submittedCount = method_exists($a, 'submissions')
                        ? $a->submissions()->count()
                        : 0;
                    $pendingCount   = max(0, $totalStudents - $submittedCount);

                    if ($pendingCount > 0) {
                        $hoursLeft   = max(0, (int) $now->diffInHours(Carbon::parse($a->due_date), false));
                        $typeLabel   = $a->type === 'Quiz' ? 'taken' : 'submitted';
                        $subjectName = $a->subject?->name ?? 'a subject';

                        $notifications->push([
                            'id'         => ++$id,
                            'title'      => 'Deadline Alert: ' . $a->title,
                            'message'    => "{$pendingCount} student" . ($pendingCount > 1 ? 's have' : ' has') . " not {$typeLabel} \"{$a->title}\" for {$subjectName}. {$hoursLeft}h remaining.",
                            'type'       => 'deadline',
                            'is_read'    => false,
                            'created_at' => $now->toIso8601String(),
                        ]);
                    }
                });
        }

        // ── 8. Account / role updates (last 3 days) ───────────────────────
        User::where('updated_at', '>=', $now->copy()->subDays(3))
            ->whereColumn('updated_at', '!=', 'created_at')
            ->whereIn('role', ['teacher', 'student'])
            ->orderByDesc('updated_at')
            ->take(3)
            ->get()
            ->each(function ($u) use (&$notifications, &$id) {
                $notifications->push([
                    'id'         => ++$id,
                    'title'      => 'Account Updated',
                    'message'    => "{$u->name}'s account information was recently updated.",
                    'type'       => 'account',
                    'is_read'    => true,
                    'created_at' => $u->updated_at->toIso8601String(),
                ]);
            });

        // ── 9. Password / security activity: teachers still on first login ─
        $pendingTeachers = User::where('role', 'teacher')
            ->where('is_first_login', true)
            ->count();

        if ($pendingTeachers > 0) {
            $notifications->push([
                'id'         => ++$id,
                'title'      => 'Pending Password Changes',
                'message'    => "{$pendingTeachers} teacher" . ($pendingTeachers > 1 ? 's have' : ' has') . " not changed their temporary password yet.",
                'type'       => 'security',
                'is_read'    => false,
                'created_at' => $now->toIso8601String(),
            ]);
        }

        $pendingStudents = User::where('role', 'student')
            ->where('is_first_login', true)
            ->count();

        if ($pendingStudents > 0) {
            $notifications->push([
                'id'         => ++$id,
                'title'      => 'Students Awaiting First Login',
                'message'    => "{$pendingStudents} student" . ($pendingStudents > 1 ? 's have' : ' has') . " not logged in yet.",
                'type'       => 'security',
                'is_read'    => true,
                'created_at' => $now->toIso8601String(),
            ]);
        }

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Error building admin notifications: ' . $e->getMessage());
        }

        // ── Sort: unread first, then newest ───────────────────────────────
        $sorted = $notifications
            ->sortBy(fn ($n) => $n['is_read'] ? 1 : 0)
            ->sortByDesc('created_at')
            ->values();

        return $this->success($sorted);
    }

    /**
     * POST /api/admin/notifications/read-all
     * Front-end handles read state locally; this is a hook for future DB storage.
     */
    public function markAllRead(): JsonResponse
    {
        return $this->success(null, 'All notifications marked as read.');
    }
}