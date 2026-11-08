<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ReportController extends BaseApiController
{
    public function summary(): JsonResponse
    {
        $totalStudents = Student::count();
        $avgGpa        = Student::where('status', 'Active')->avg('gpa') ?? 0;
        $passingCount  = Student::where('gpa', '>=', 75)->count();
        $passingRate   = $totalStudents > 0
            ? round(($passingCount / $totalStudents) * 100, 1)
            : 0;

        return $this->success([
            'total_students'  => $totalStudents,
            'total_teachers'  => Teacher::count(),
            'total_subjects'  => Subject::count(),

            // Active classes = subjects that have ≥1 teacher assigned via pivot
            // Falls back to sum of classes_count if pivot table doesn't exist yet
            'active_classes'  => $this->activeClasses(),

            'avg_gpa'         => round($avgGpa, 2),
            'passing_rate'    => $passingRate,
        ]);
    }

    /** Safely count active classes from pivot or fall back to legacy sum */
    private function activeClasses(): int
    {
        try {
            return Subject::whereHas('teachers')->count();
        } catch (\Throwable $e) {
            // Fallback: sum classes_count from teachers table
            return (int) Teacher::where('status', 'Active')->sum('classes_count');
        }
    }

    public function studentPerformance(): JsonResponse
    {
        $students = Student::select('id', 'first_name', 'middle_name', 'last_name', 'suffix', 'grade', 'gpa', 'status')
            ->with('section:id,name')
            ->orderBy('gpa', 'desc')
            ->get()
            ->map(fn ($s) => [
                'id'           => $s->id,
                'name'         => $s->full_name,
                'grade'        => $s->grade,
                'section_name' => $s->section?->name ?? '—',
                'gpa'          => $s->gpa,
                'status'       => $s->status,
            ]);

        return $this->success($students);
    }

    public function teacherActivity(): JsonResponse
    {
        $teachers = Teacher::with('subjects')
            ->select('id', 'name', 'subject', 'grade_level', 'classes_count', 'status')
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id'             => $t->id,
                'name'           => $t->name,
                // Show subjects from pivot if available, fallback to legacy text column
                'subject'        => $t->subjects->isNotEmpty()
                    ? $t->subjects->pluck('name')->implode(', ')
                    : ($t->subject ?: '—'),
                'grade_level'    => $t->grade_level,
                'classes_count'  => $t->classes_count,
                'subjects_count' => $t->subjects->count(),
                'status'         => $t->status,
            ]);

        return $this->success($teachers);
    }

    public function systemUsage(): JsonResponse
    {
        // Count students who have logged in at least once (is_first_login = false)
        $activeStudents = User::where('role', 'student')
            ->where('is_first_login', false)
            ->count();

        // Count teachers who have changed their password (no longer on first login)
        $activeTeachers = User::where('role', 'teacher')
            ->where('is_first_login', false)
            ->count();

        // Teachers still on temporary password
        $pendingTeachers = User::where('role', 'teacher')
            ->where('is_first_login', true)
            ->count();

        // Students still on default password
        $pendingStudents = User::where('role', 'student')
            ->where('is_first_login', true)
            ->count();

        // New accounts created this week
        $newThisWeek = User::whereIn('role', ['student', 'teacher'])
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        // Total active tokens (active sessions)
        $activeSessions = DB::table('personal_access_tokens')
            ->where('last_used_at', '>=', now()->subHours(24))
            ->count();

        return $this->success([
            ['label' => 'Active Students',     'value' => $activeStudents,  'desc' => 'Logged in at least once'],
            ['label' => 'Active Teachers',     'value' => $activeTeachers,  'desc' => 'Password changed, active'],
            ['label' => 'Pending Teachers',    'value' => $pendingTeachers, 'desc' => 'Awaiting first login'],
            ['label' => 'Pending Students',    'value' => $pendingStudents, 'desc' => 'Awaiting first login'],
            ['label' => 'New This Week',       'value' => $newThisWeek,     'desc' => 'Accounts created this week'],
            ['label' => 'Active Sessions',     'value' => $activeSessions,  'desc' => 'Tokens used in last 24h'],
        ]);
    }
}