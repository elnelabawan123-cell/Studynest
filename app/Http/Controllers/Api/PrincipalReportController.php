<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Teacheractivitylog;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrincipalReportController extends Controller
{
    /* ═══════════════════════════════════════════════════════════════ *
     *  1. SCHOOL OVERVIEW — totals across the whole school             *
     * ═══════════════════════════════════════════════════════════════ */
    public function schoolOverview(Request $request): JsonResponse
    {
        $from = $request->date('date_from');
        $to   = $request->date('date_to');

        // ── Headcounts ────────────────────────────────────────
        $totalTeachers       = Teacher::where('status', 'Active')->count();
        $inactiveTeachers    = Teacher::where('status', 'Inactive')->count();
        $totalStudents       = Student::where('status', 'Active')->count();
        $inactiveStudents    = Student::where('status', 'Inactive')->count();
        $totalSubjects       = Subject::where('status', 'Active')->count();
        $totalSections       = Section::where('is_active', true)->count();

        // ── Content created (filterable by date range) ───────
        $lessonsQuery     = Lesson::query();
        $activitiesQuery  = Activity::query();
        if ($from) { $lessonsQuery->where('created_at', '>=', $from); $activitiesQuery->where('created_at', '>=', $from); }
        if ($to)   { $lessonsQuery->where('created_at', '<=', $to->copy()->endOfDay()); $activitiesQuery->where('created_at', '<=', $to->copy()->endOfDay()); }

        $totalLessons     = (clone $lessonsQuery)->count();
        $publishedLessons = (clone $lessonsQuery)->where('status', 'published')->count();
        $draftLessons     = (clone $lessonsQuery)->where('status', 'draft')->count();

        $totalActivities  = (clone $activitiesQuery)->count();
        $totalQuizzes     = (clone $activitiesQuery)->where('type', 'Quiz')->count();
        $totalAssignments = (clone $activitiesQuery)->where('type', 'Assignment')->count();
        $totalTasks       = (clone $activitiesQuery)->where('type', 'Activity')->count();

        // ── Engagement — average lesson views per student ────
        $totalViews = DB::table('lessons')->sum('views');
        $avgViews   = $totalStudents > 0 ? round($totalViews / $totalStudents, 1) : 0;

        return response()->json([
            'generated_at' => now()->format('M j, Y h:i A'),
            'principal'    => ['name' => $request->user()?->name ?? 'Principal'],
            'date_from'    => $from?->format('Y-m-d'),
            'date_to'      => $to?->format('Y-m-d'),

            'overview' => [
                // People
                'total_active_teachers'   => $totalTeachers,
                'inactive_teachers'       => $inactiveTeachers,
                'total_active_students'   => $totalStudents,
                'inactive_students'       => $inactiveStudents,

                // Structure
                'total_active_subjects'   => $totalSubjects,
                'total_active_sections'   => $totalSections,

                // Content
                'total_lessons'           => $totalLessons,
                'published_lessons'       => $publishedLessons,
                'draft_lessons'           => $draftLessons,
                'total_activities'        => $totalActivities,
                'total_quizzes'           => $totalQuizzes,
                'total_assignments'       => $totalAssignments,
                'total_tasks'             => $totalTasks,

                // Engagement
                'total_lesson_views'      => $totalViews,
                'avg_views_per_student'   => $avgViews,
            ],
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  2. TEACHER PERFORMANCE SUMMARY                                  *
     * ═══════════════════════════════════════════════════════════════ */
    public function teacherPerformance(Request $request): JsonResponse
    {
        $from = $request->date('date_from');
        $to   = $request->date('date_to');

        $teachers = Teacher::with('subjects')
            ->orderBy('name')
            ->get();

        $rows = $teachers->map(function (Teacher $t) use ($from, $to) {
            // Find the auth user this teacher maps to (by email)
            $authUserId = User::where('email', $t->email)->value('id');

            // Subjects handled
            $subjectNames = $t->subjects->pluck('name')->unique()->values();

            // Total students across all their subjects (de-duplicated)
            $studentIds = DB::table('subject_student')
                ->whereIn('subject_id', $t->subjects->pluck('id'))
                ->pluck('student_id')
                ->unique();
            $studentsCount = $studentIds->count();

            // Lessons + activities created (filterable by date)
            $lessonsQuery    = Lesson::where('teacher_id', $authUserId);
            $activitiesQuery = Activity::where('teacher_id', $authUserId);
            if ($from) { $lessonsQuery->where('created_at', '>=', $from); $activitiesQuery->where('created_at', '>=', $from); }
            if ($to)   { $lessonsQuery->where('created_at', '<=', $to->copy()->endOfDay()); $activitiesQuery->where('created_at', '<=', $to->copy()->endOfDay()); }

            $lessonsCount     = (clone $lessonsQuery)->count();
            $quizzesCount     = (clone $activitiesQuery)->where('type', 'Quiz')->count();
            $assignmentsCount = (clone $activitiesQuery)->where('type', 'Assignment')->count();
            $tasksCount       = (clone $activitiesQuery)->where('type', 'Activity')->count();

            // Last activity timestamp (most recent log)
            $lastActive = Teacheractivitylog::where('teacher_id', $authUserId)
                ->latest('created_at')
                ->value('created_at');

            return [
                'teacher_id'        => $t->id,
                'name'              => $t->name,
                'subjects'          => $subjectNames->implode(', ') ?: '—',
                'subjects_count'    => $subjectNames->count(),
                'students_count'    => $studentsCount,
                'lessons_count'     => $lessonsCount,
                'quizzes_count'     => $quizzesCount,
                'assignments_count' => $assignmentsCount,
                'tasks_count'       => $tasksCount,
                'total_activities'  => $quizzesCount + $assignmentsCount + $tasksCount,
                'last_active'       => $lastActive ? Carbon::parse($lastActive)->format('M j, Y') : '—',
                'status'            => $t->status,
            ];
        });

        return response()->json([
            'generated_at' => now()->format('M j, Y h:i A'),
            'principal'    => ['name' => $request->user()?->name ?? 'Principal'],
            'date_from'    => $from?->format('Y-m-d'),
            'date_to'      => $to?->format('Y-m-d'),
            'totals'       => [
                'total_teachers'   => $rows->count(),
                'active_teachers'  => $rows->where('status', 'Active')->count(),
                'total_lessons'    => $rows->sum('lessons_count'),
                'total_activities' => $rows->sum('total_activities'),
            ],
            'rows'         => $rows->values(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  3. SUBJECT COVERAGE REPORT                                      *
     * ═══════════════════════════════════════════════════════════════ */
    public function subjectCoverage(Request $request): JsonResponse
    {
        $from = $request->date('date_from');
        $to   = $request->date('date_to');

        $subjects = Subject::with('teachers')
            ->orderBy('grade_level')
            ->orderBy('name')
            ->get();

        $rows = $subjects->map(function (Subject $s) use ($from, $to) {
            $teacherNames = $s->teachers->pluck('name')->implode(', ') ?: '— Unassigned —';

            $studentsCount = DB::table('subject_student')
                ->where('subject_id', $s->id)
                ->count();

            // Lessons + activities for this subject
            $lessonsQuery    = Lesson::where('subject_id', $s->id);
            $activitiesQuery = Activity::where('subject_id', $s->id);
            if ($from) { $lessonsQuery->where('created_at', '>=', $from); $activitiesQuery->where('created_at', '>=', $from); }
            if ($to)   { $lessonsQuery->where('created_at', '<=', $to->copy()->endOfDay()); $activitiesQuery->where('created_at', '<=', $to->copy()->endOfDay()); }

            $lessonsCount    = (clone $lessonsQuery)->count();
            $activitiesCount = (clone $activitiesQuery)->count();

            // Submissions for this subject (avg per student)
            $submissionsCount = DB::table('student_activity_submissions')
                ->where('subject_id', $s->id)
                ->where('status', 'Submitted')
                ->count();
            $avgSubsPerStudent = $studentsCount > 0
                ? round($submissionsCount / $studentsCount, 1)
                : 0;

            return [
                'subject_id'        => $s->id,
                'name'              => $s->name,
                'code'              => $s->code,
                'grade_level'       => $s->grade_level,
                'teachers'          => $teacherNames,
                'students_count'    => $studentsCount,
                'lessons_count'     => $lessonsCount,
                'activities_count'  => $activitiesCount,
                'submissions_count' => $submissionsCount,
                'avg_submissions'   => $avgSubsPerStudent,
                'status'            => $s->status,
            ];
        });

        return response()->json([
            'generated_at' => now()->format('M j, Y h:i A'),
            'principal'    => ['name' => $request->user()?->name ?? 'Principal'],
            'date_from'    => $from?->format('Y-m-d'),
            'date_to'      => $to?->format('Y-m-d'),
            'totals'       => [
                'total_subjects'   => $rows->count(),
                'active_subjects'  => $rows->where('status', 'Active')->count(),
                'total_lessons'    => $rows->sum('lessons_count'),
                'total_activities' => $rows->sum('activities_count'),
            ],
            'rows'         => $rows->values(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  4. GRADE LEVEL DISTRIBUTION                                     *
     * ═══════════════════════════════════════════════════════════════ */
    public function gradeDistribution(Request $request): JsonResponse
    {
        $grades = [
            'Grade 1', 'Grade 2', 'Grade 3',
            'Grade 4', 'Grade 5', 'Grade 6',
        ];

        $rows = collect($grades)->map(function (string $grade) {
            $studentsCount = Student::where('grade', $grade)
                ->where('status', 'Active')
                ->count();

            $sectionsCount = Section::where('grade_level', $grade)
                ->where('is_active', true)
                ->count();

            $subjectsCount = Subject::where('grade_level', $grade)
                ->where('status', 'Active')
                ->count();

            // Distinct teachers handling subjects in this grade
            $teachersCount = DB::table('teachers')
                ->join('subject_teacher', 'subject_teacher.teacher_id', '=', 'teachers.id')
                ->join('subjects', 'subjects.id', '=', 'subject_teacher.subject_id')
                ->where('subjects.grade_level', $grade)
                ->where('teachers.status', 'Active')
                ->distinct('teachers.id')
                ->count('teachers.id');

            $avgPerSection = $sectionsCount > 0
                ? round($studentsCount / $sectionsCount, 1)
                : 0;

            return [
                'grade_level'      => $grade,
                'students_count'   => $studentsCount,
                'sections_count'   => $sectionsCount,
                'avg_per_section'  => $avgPerSection,
                'teachers_count'   => $teachersCount,
                'subjects_count'   => $subjectsCount,
            ];
        });

        return response()->json([
            'generated_at' => now()->format('M j, Y h:i A'),
            'principal'    => ['name' => $request->user()?->name ?? 'Principal'],
            'totals'       => [
                'total_students' => $rows->sum('students_count'),
                'total_sections' => $rows->sum('sections_count'),
            ],
            'rows'         => $rows->values(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  5. EXPORT (PDF or Word)                                         *
     * ═══════════════════════════════════════════════════════════════ */
    public function export(Request $request)
    {
        $reportType = $request->string('report_type')->toString();
        $format     = $request->string('format')->toString() ?: 'pdf';

        $data = match ($reportType) {
            'school_overview'      => $this->schoolOverview($request)->getData(true),
            'teacher_performance'  => $this->teacherPerformance($request)->getData(true),
            'subject_coverage'     => $this->subjectCoverage($request)->getData(true),
            'grade_distribution'   => $this->gradeDistribution($request)->getData(true),
            default                => abort(422, 'Unknown report_type'),
        };

        $filename = "principal_{$reportType}_" . now()->format('Ymd_His');

        if ($format === 'word') {
            return $this->exportWord($reportType, $data, $filename);
        }
        return $this->exportPdf($reportType, $data, $filename);
    }

    protected function exportPdf(string $reportType, array $data, string $filename)
    {
        $pdf = Pdf::loadView('reports.principal-export-pdf', [
            'report_type'  => $reportType,
            'data'         => $data,
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$filename}.pdf");
    }

    protected function exportWord(string $reportType, array $data, string $filename): StreamedResponse
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        // ── Official school letterhead ──────────────────────────────
        $churchLogoPath = public_path('images/panabo-sda-mega-church-logo.jpg');
        $schoolLogoPath = public_path('images/panabo-sda-logo.jpg');

        $lhTable = $section->addTable(['borderSize' => 0, 'cellMargin' => 0, 'width' => 100 * 50, 'unit' => 'pct']);
        $lhTable->addRow();

        $logoCellStyle = ['borderSize' => 0, 'valign' => 'center'];
        $textCellStyle = ['borderSize' => 0, 'valign' => 'center'];

        $leftCell = $lhTable->addCell(1200, $logoCellStyle);
        if (is_file($churchLogoPath)) {
            $leftCell->addImage($churchLogoPath, ['width' => 50, 'height' => 50, 'align' => Jc::CENTER]);
        }

        $centerCell = $lhTable->addCell(7600, $textCellStyle);
        $centerCell->addText('PANABO SDA LEARNING CENTER, INC.', ['bold' => true, 'size' => 15, 'color' => '14532D'], ['alignment' => Jc::CENTER]);
        $centerCell->addText('"Developing Leaders Today That Will Walk Jesus into Eternity"', ['italic' => true, 'size' => 9], ['alignment' => Jc::CENTER]);
        $centerCell->addText('Government Recognition No. 002 S. 2013  •  School ID No. 405590', ['size' => 7.5, 'color' => '6B7280'], ['alignment' => Jc::CENTER]);
        $centerCell->addText('Panabo City', ['size' => 7.5, 'color' => '6B7280'], ['alignment' => Jc::CENTER]);

        $rightCell = $lhTable->addCell(1200, $logoCellStyle);
        if (is_file($schoolLogoPath)) {
            $rightCell->addImage($schoolLogoPath, ['width' => 50, 'height' => 50, 'align' => Jc::CENTER]);
        }

        // Double rule under the letterhead
        $section->addLine([
            'weight' => 2, 'width' => 460, 'height' => 0, 'align' => Jc::CENTER,
        ]);
        $section->addTextBreak(1);

        // ── Report header ────────────────────────────────────────────
        $section->addText('GENERATED VIA STUDYNEST LEARNING MANAGEMENT SYSTEM', ['size' => 7, 'color' => '9CA3AF'], ['alignment' => Jc::CENTER, 'spaceAfter' => 40]);
        $section->addText($this->prettyTitle($reportType), ['bold' => true, 'size' => 14, 'color' => '6D28D9'], ['alignment' => Jc::CENTER]);
        $section->addText('Official Document — Submitted to the School President', ['italic' => true, 'size' => 9, 'color' => '6B7280'], ['alignment' => Jc::CENTER]);
        $section->addText('Prepared by: ' . ($data['principal']['name'] ?? 'Principal'), ['italic' => true, 'size' => 10], ['alignment' => Jc::CENTER]);
        $section->addText('Generated: ' . ($data['generated_at'] ?? now()->format('M j, Y h:i A')), ['italic' => true, 'size' => 10], ['alignment' => Jc::CENTER]);
        if (!empty($data['date_from']) || !empty($data['date_to'])) {
            $section->addText('Period: ' . ($data['date_from'] ?? '—') . ' to ' . ($data['date_to'] ?? '—'), ['italic' => true, 'size' => 10], ['alignment' => Jc::CENTER]);
        }
        $section->addTextBreak(1);

        $rows = $this->flattenForExport($reportType, $data);
        if (!empty($rows['headers'])) {
            $phpWord->addTableStyle('reportTable', [
                'borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80,
            ], ['bgColor' => 'EDE9FE']);
            $table = $section->addTable('reportTable');

            $table->addRow();
            foreach ($rows['headers'] as $h) {
                $table->addCell(2200)->addText($h, ['bold' => true, 'size' => 10]);
            }
            foreach ($rows['rows'] as $row) {
                $table->addRow();
                foreach ($row as $cell) {
                    $table->addCell(2200)->addText((string) $cell, ['size' => 10]);
                }
            }
        }

        $tempPath = storage_path('app/' . $filename . '.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);

        return response()->streamDownload(function () use ($tempPath) {
            readfile($tempPath);
            @unlink($tempPath);
        }, "{$filename}.docx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /* ─── Helpers ─────────────────────────────────────────────────── */

    protected function prettyTitle(string $type): string
    {
        return match ($type) {
            'school_overview'     => 'School Overview Report',
            'teacher_performance' => 'Teacher Performance Summary',
            'subject_coverage'    => 'Subject Coverage Report',
            'grade_distribution'  => 'Grade Level Distribution Report',
            default               => 'Report',
        };
    }

    protected function flattenForExport(string $type, array $data): array
    {
        if ($type === 'school_overview') {
            $o = $data['overview'] ?? [];
            $headers = ['Metric', 'Value'];
            $rows = [
                ['Total Active Teachers',     $o['total_active_teachers']   ?? 0],
                ['Inactive Teachers',         $o['inactive_teachers']       ?? 0],
                ['Total Active Students',     $o['total_active_students']   ?? 0],
                ['Inactive Students',         $o['inactive_students']       ?? 0],
                ['Total Active Subjects',     $o['total_active_subjects']   ?? 0],
                ['Total Active Sections',     $o['total_active_sections']   ?? 0],
                ['Total Lessons',             $o['total_lessons']           ?? 0],
                ['Published Lessons',         $o['published_lessons']       ?? 0],
                ['Draft Lessons',             $o['draft_lessons']           ?? 0],
                ['Total Activities',          $o['total_activities']        ?? 0],
                ['Quizzes',                   $o['total_quizzes']           ?? 0],
                ['Assignments',               $o['total_assignments']       ?? 0],
                ['Tasks / Activities',        $o['total_tasks']             ?? 0],
                ['Total Lesson Views',        $o['total_lesson_views']      ?? 0],
                ['Avg Views per Student',     $o['avg_views_per_student']   ?? 0],
            ];
            return compact('headers', 'rows');
        }

        if ($type === 'teacher_performance') {
            $headers = ['Teacher', 'Subjects', 'Students', 'Lessons', 'Quizzes', 'Assignments', 'Tasks', 'Last Active', 'Status'];
            $rows = collect($data['rows'] ?? [])->map(fn ($r) => [
                $r['name'], $r['subjects'], $r['students_count'],
                $r['lessons_count'], $r['quizzes_count'], $r['assignments_count'], $r['tasks_count'],
                $r['last_active'], $r['status'],
            ])->all();
            return compact('headers', 'rows');
        }

        if ($type === 'subject_coverage') {
            $headers = ['Code', 'Name', 'Grade', 'Teacher(s)', 'Students', 'Lessons', 'Activities', 'Submissions', 'Status'];
            $rows = collect($data['rows'] ?? [])->map(fn ($r) => [
                $r['code'], $r['name'], $r['grade_level'], $r['teachers'],
                $r['students_count'], $r['lessons_count'], $r['activities_count'],
                $r['submissions_count'], $r['status'],
            ])->all();
            return compact('headers', 'rows');
        }

        if ($type === 'grade_distribution') {
            $headers = ['Grade Level', 'Students', 'Sections', 'Avg/Section', 'Teachers', 'Subjects'];
            $rows = collect($data['rows'] ?? [])->map(fn ($r) => [
                $r['grade_level'], $r['students_count'], $r['sections_count'],
                $r['avg_per_section'], $r['teachers_count'], $r['subjects_count'],
            ])->all();
            return compact('headers', 'rows');
        }

        return ['headers' => [], 'rows' => []];
    }
}