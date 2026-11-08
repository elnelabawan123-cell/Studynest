<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lesson;
use App\Models\Lessonstracking;
use App\Models\Studentactivitysubmission;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Teacheractivitylog;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TeacherreportController extends Controller
{
    /* ───────────────────────────────────────────────────────────────
     |  SCHEMA NOTES — important for understanding this controller
     |
     |  users (auth)   → email matches teachers.email
     |  teachers       → has subjects()  (pivot subject_teacher)
     |  subjects       → has students()  (pivot subject_student → students.id)
     |  students       → has user_id → users.id (for the name display)
     |  ─────────────────────────────────────────────────────────────── */

    /**
     * Resolve the Teacher row for the authenticated user.
     * Throws 404 if no teacher profile is linked to this user's email.
     */
    protected function resolveTeacher(Request $request): Teacher
    {
        $user = $request->user();
        $teacher = Teacher::where('email', $user->email)->first();
        abort_if(!$teacher, Response::HTTP_NOT_FOUND, 'Teacher profile not found.');
        return $teacher;
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  1. SUBJECTS — only those assigned to the logged-in teacher     *
     * ═══════════════════════════════════════════════════════════════ */
    public function subjects(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);

        $subjects = $teacher->subjects()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get(['subjects.id', 'subjects.code', 'subjects.name', 'subjects.grade_level']);

        return response()->json(['data' => $subjects]);
    }

    public function activities(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $subjectId = $request->integer('subject_id');
        $activityType = $request->input('activity_type');

        $this->ensureOwnsSubject($teacher->id, $subjectId);
        $request->validate([
            'activity_type' => ['nullable', 'in:Assignment,Quiz,Activity'],
        ]);

        $activities = Activity::query()
            ->where('subject_id', $subjectId)
            ->where('teacher_id', $request->user()->id)
            ->when($activityType, fn ($query) => $query->where('type', $activityType))
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'type', 'due_date', 'total_points']);

        return response()->json(['data' => $activities]);
    }

    public function taskReport(Request $request): JsonResponse
    {
        $teacher = $this->resolveTeacher($request);
        $subjectId = $request->integer('subject_id');
        $activityId = $request->integer('activity_id');
        $studentId = $request->integer('student_id') ?: null;
        $from = $request->date('date_from');
        $to = $request->date('date_to');

        $this->ensureOwnsSubject($teacher->id, $subjectId);
        $request->validate([
            'activity_id' => ['required', 'integer'],
            'student_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $activity = Activity::query()
            ->where('id', $activityId)
            ->where('subject_id', $subjectId)
            ->where('teacher_id', $request->user()->id)
            ->firstOrFail();

        $students = $this->enrolledStudents($subjectId, $studentId);
        if ($studentId && $students->isEmpty()) {
            abort(404, 'Student is not enrolled in this subject.');
        }

        $studentUserIds = $students->map(fn ($student) => $student->user_id ?? $student->id);
        $submissionsQuery = Studentactivitysubmission::query()
            ->where('subject_id', $subjectId)
            ->where('activity_id', $activity->id)
            ->whereIn('student_id', $studentUserIds);
        if ($from) $submissionsQuery->where('submitted_at', '>=', $from);
        if ($to) $submissionsQuery->where('submitted_at', '<=', $to->copy()->endOfDay());

        $submissions = $submissionsQuery
            ->orderByDesc('submitted_at')
            ->get()
            ->groupBy('student_id');

        $rows = $students->map(function ($student) use ($submissions) {
            $submission = $submissions->get($student->user_id ?? $student->id)?->first();

            return [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'status' => $submission?->status ?? 'Not submitted',
                'score' => $submission?->score,
                'submitted_at' => $submission?->submitted_at?->format('M j, Y'),
            ];
        });

        $subject = Subject::findOrFail($subjectId);
        return response()->json([
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'grade_level' => $subject->grade_level],
            'activity' => [
                'id' => $activity->id,
                'title' => $activity->title,
                'type' => $activity->type,
                'total_points' => $activity->total_points,
            ],
            'date_from' => $from?->format('Y-m-d'),
            'date_to' => $to?->format('Y-m-d'),
            'rows' => $rows->values(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  2. STUDENT LESSON & ACTIVITY REPORT                            *
     * ═══════════════════════════════════════════════════════════════ */
    public function studentLessonActivity(Request $request): JsonResponse
    {
        $teacher   = $this->resolveTeacher($request);
        $subjectId = $request->integer('subject_id');
        $studentId = $request->integer('student_id') ?: null;
        $from      = $request->date('date_from');
        $to        = $request->date('date_to');

        $this->ensureOwnsSubject($teacher->id, $subjectId);

        // Students enrolled in this subject (via subject_student → students)
        $studentRows = $this->enrolledStudents($subjectId, $studentId);

        $rows = $studentRows->map(function ($student) use ($subjectId, $from, $to) {
            $studentUserId = $student->user_id ?? $student->id;  // tracking uses user id

            // Lesson tracking
            $lessonsQuery = Lessonstracking::where('student_id', $studentUserId)
                ->where('subject_id', $subjectId);
            if ($from) $lessonsQuery->where('created_at', '>=', $from);
            if ($to)   $lessonsQuery->where('created_at', '<=', $to->copy()->endOfDay());

            $views     = (clone $lessonsQuery)->where('action_type', 'view')->count();
            $downloads = (clone $lessonsQuery)->where('action_type', 'download')->count();

            $lessonTitles = (clone $lessonsQuery)
                ->with('lesson:id,title')
                ->get()
                ->pluck('lesson.title')
                ->filter()
                ->unique()
                ->values()
                ->all();

            // Submissions
            $subsQuery = Studentactivitysubmission::where('student_id', $studentUserId)
                ->where('subject_id', $subjectId);
            if ($from) $subsQuery->where('submitted_at', '>=', $from);
            if ($to)   $subsQuery->where('submitted_at', '<=', $to->copy()->endOfDay());

            $assignment = (clone $subsQuery)->where('activity_type', 'Assignment')->latest('submitted_at')->first();
            $task       = (clone $subsQuery)->where('activity_type', 'Activity')->latest('submitted_at')->first();
            $quiz       = (clone $subsQuery)->where('activity_type', 'Quiz')->latest('submitted_at')->first();

            $lastSubmitted = $subsQuery->max('submitted_at');

            return [
                'student_id'         => $student->id,
                'student_name'       => $student->name,
                'lessons_viewed'     => $views,
                'lessons_downloaded' => $downloads,
                'lesson_titles'      => $lessonTitles,
                'assignment_status'  => $assignment?->status ?? '—',
                'task_status'        => $task?->status ?? '—',
                'quiz_status'        => $quiz?->status ?? '—',
                'score'              => $quiz?->score ?? $assignment?->score ?? '—',
                'date_completed'     => $lastSubmitted ? Carbon::parse($lastSubmitted)->format('M j, Y') : '—',
                'remarks'            => $this->buildRemarks($views, $downloads, $assignment, $task, $quiz),
            ];
        });

        $subject = Subject::find($subjectId);

        return response()->json([
            'teacher'   => ['id' => $teacher->id, 'name' => $teacher->name],
            'subject'   => $subject ? ['id' => $subject->id, 'name' => $subject->name, 'grade_level' => $subject->grade_level] : null,
            'date_from' => $from?->format('Y-m-d'),
            'date_to'   => $to?->format('Y-m-d'),
            'rows'      => $rows->values(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  3. WEEKLY TEACHER ACTIVITY REPORT — grouped by subject + day   *
     * ═══════════════════════════════════════════════════════════════ */
    public function weeklyActivity(Request $request): JsonResponse
    {
        $teacher   = $this->resolveTeacher($request);
        $subjectId = $request->integer('subject_id') ?: null;

        $weekStart = $request->date('week_start') ?? now()->startOfWeek();
        $weekEnd   = $request->date('week_end')   ?? (clone $weekStart)->endOfWeek();

        if ($subjectId) {
            $this->ensureOwnsSubject($teacher->id, $subjectId);
        }

        // Activity logs use users.id as teacher_id (because that's the auth user),
        // but our auth user IS the teacher in this app.
        $authUserId = $request->user()->id;

        $query = Teacheractivitylog::where('teacher_id', $authUserId)
            ->whereBetween('created_at', [$weekStart, $weekEnd->copy()->endOfDay()])
            ->with('subject:id,name,grade_level')
            ->orderBy('created_at');

        if ($subjectId) {
            $query->where('subject_id', $subjectId);
        }

        $logs = $query->get();

        $bySubject = [];
        foreach ($logs as $log) {
            $subjKey = $log->subject?->name ?? 'Unassigned';
            $day     = Carbon::parse($log->created_at)->format('l');

            $bySubject[$subjKey][$day][] = [
                'type'         => $this->prettyActivityType($log->activity_type),
                'title'        => $log->title,
                'description'  => $log->description,
                'status'       => $log->status,
                'created_date' => Carbon::parse($log->created_at)->format('M j, Y'),
                'created_time' => Carbon::parse($log->created_at)->format('h:i A'),
            ];
        }

        $orderedDays = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $output = [];
        foreach ($bySubject as $subjName => $days) {
            $subjectMeta = $logs->firstWhere(fn ($l) => ($l->subject?->name ?? 'Unassigned') === $subjName)?->subject;

            $orderedDayList = [];
            foreach ($orderedDays as $dayName) {
                $orderedDayList[] = ['day' => $dayName, 'entries' => $days[$dayName] ?? []];
            }

            $output[] = [
                'subject'     => $subjName,
                'grade_level' => $subjectMeta?->grade_level ?? '',
                'days'        => $orderedDayList,
            ];
        }

        return response()->json([
            'teacher'   => ['id' => $teacher->id, 'name' => $teacher->name],
            'week_start'=> Carbon::parse($weekStart)->format('Y-m-d'),
            'week_end'  => Carbon::parse($weekEnd)->format('Y-m-d'),
            'subjects'  => $output,
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  4. SUBJECT REPORT                                               *
     * ═══════════════════════════════════════════════════════════════ */
    public function subjectReport(Request $request): JsonResponse
    {
        $teacher   = $this->resolveTeacher($request);
        $subjectId = $request->integer('subject_id');
        $activityType = $request->input('activity_type');
        $from      = $request->date('date_from');
        $to        = $request->date('date_to');

        $this->ensureOwnsSubject($teacher->id, $subjectId);
        $request->validate([
            'activity_type' => ['nullable', 'in:Assignment,Quiz,Activity'],
        ]);

        $subject = Subject::findOrFail($subjectId);
        $authUserId = $request->user()->id;

        $logsQuery = Teacheractivitylog::where('teacher_id', $authUserId)
            ->where('subject_id', $subjectId);
        if ($from) $logsQuery->where('created_at', '>=', $from);
        if ($to)   $logsQuery->where('created_at', '<=', $to->copy()->endOfDay());

        $logCounts = (clone $logsQuery)
            ->select('activity_type', DB::raw('count(*) as c'))
            ->groupBy('activity_type')
            ->pluck('c', 'activity_type');

        $studentRows = $this->enrolledStudents($subjectId);

        $rows = $studentRows->map(function ($student) use ($subjectId, $from, $to, $activityType) {
            $studentUserId = $student->user_id ?? $student->id;

            $lessonsQuery = Lessonstracking::where('student_id', $studentUserId)
                ->where('subject_id', $subjectId);
            if ($from) $lessonsQuery->where('created_at', '>=', $from);
            if ($to)   $lessonsQuery->where('created_at', '<=', $to->copy()->endOfDay());

            $lessonsAccessed = (clone $lessonsQuery)->select('lesson_id')->distinct()->count('lesson_id');

            $subsQuery = Studentactivitysubmission::where('student_id', $studentUserId)
                ->where('subject_id', $subjectId)
                ->where('status', 'Submitted');
            if ($activityType) $subsQuery->where('activity_type', $activityType);
            if ($from) $subsQuery->where('submitted_at', '>=', $from);
            if ($to)   $subsQuery->where('submitted_at', '<=', $to->copy()->endOfDay());

            $assignmentSubs = (clone $subsQuery)->where('activity_type', 'Assignment')->count();
            $taskSubs       = (clone $subsQuery)->where('activity_type', 'Activity')->count();
            $quizSubs       = (clone $subsQuery)->where('activity_type', 'Quiz')->count();

            $avgScore = (clone $subsQuery)->whereNotNull('score')->avg('score');
            $total = $assignmentSubs + $taskSubs + $quizSubs;

            return [
                'student_id'            => $student->id,
                'student_name'          => $student->name,
                'lessons_accessed'      => $lessonsAccessed,
                'assignments_submitted' => $assignmentSubs,
                'tasks_completed'       => $taskSubs,
                'quizzes_taken'         => $quizSubs,
                'average_score'         => $avgScore !== null ? round((float) $avgScore, 2) : '—',
                'completion_status'     => $this->completionLabel($total),
                'remarks'               => $this->studentRemarks($avgScore, $total),
            ];
        });

        $filteredLogCounts = $activityType
            ? collect()
            : $logCounts;

        return response()->json([
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
            'subject' => [
                'id'           => $subject->id,
                'name'         => $subject->name,
                'grade_level'  => $subject->grade_level,
                'teacher_name' => $teacher->name,
            ],
            'totals'  => [
                'total_students'    => $studentRows->count(),
                'total_lessons'     => $activityType ? 0 : (int) ($filteredLogCounts['lesson_uploaded'] ?? 0) + (int) ($filteredLogCounts['lesson_published'] ?? 0),
                'total_assignments' => !$activityType || $activityType === 'Assignment' ? (int) ($logCounts['assignment_created'] ?? 0) : 0,
                'total_tasks'       => !$activityType || $activityType === 'Activity' ? (int) ($logCounts['task_created'] ?? 0) : 0,
                'total_quizzes'     => !$activityType || $activityType === 'Quiz' ? (int) ($logCounts['quiz_created'] ?? 0) : 0,
            ],
            'activity_type' => $activityType,
            'date_from' => $from?->format('Y-m-d'),
            'date_to'   => $to?->format('Y-m-d'),
            'students'  => $rows->values(),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  5. SINGLE STUDENT REPORT                                        *
     * ═══════════════════════════════════════════════════════════════ */
    public function studentReport(Request $request): JsonResponse
    {
        $teacher   = $this->resolveTeacher($request);
        $subjectId = $request->integer('subject_id');
        $studentId = $request->integer('student_id'); // students.id
        $activityType = $request->input('activity_type');
        $from      = $request->date('date_from');
        $to        = $request->date('date_to');

        $this->ensureOwnsSubject($teacher->id, $subjectId);
        $request->validate([
            'activity_type' => ['nullable', 'in:Assignment,Quiz,Activity'],
        ]);

        // Look up the student (from students table) + their auth user
        $student = DB::table('students')->where('id', $studentId)->first();
        abort_if(!$student, 404, 'Student not found.');
        abort_unless(
            DB::table('subject_student')
                ->where('subject_id', $subjectId)
                ->where('student_id', $studentId)
                ->exists(),
            404,
            'Student is not enrolled in this subject.'
        );

        $studentUserId = $student->user_id ?? $student->id;
        $studentName   = trim(($student->first_name ?? '') . ' ' . ($student->last_name ?? ''));
        if ($studentName === '' && $student->user_id) {
            $studentName = DB::table('users')->where('id', $student->user_id)->value('name') ?? 'Student';
        }

        $subject = Subject::findOrFail($subjectId);

        $lessonsQuery = Lessonstracking::where('student_id', $studentUserId)
            ->where('subject_id', $subjectId);
        if ($from) $lessonsQuery->where('created_at', '>=', $from);
        if ($to)   $lessonsQuery->where('created_at', '<=', $to->copy()->endOfDay());

        $views     = (clone $lessonsQuery)->where('action_type', 'view')->count();
        $downloads = (clone $lessonsQuery)->where('action_type', 'download')->count();

        $subsQuery = Studentactivitysubmission::where('student_id', $studentUserId)
            ->where('subject_id', $subjectId);
        if ($activityType) $subsQuery->where('activity_type', $activityType);
        if ($from) $subsQuery->where('submitted_at', '>=', $from);
        if ($to)   $subsQuery->where('submitted_at', '<=', $to->copy()->endOfDay());

        $assignments = (clone $subsQuery)->where('activity_type', 'Assignment')->get();
        $tasks       = (clone $subsQuery)->where('activity_type', 'Activity')->get();
        $quizzes     = (clone $subsQuery)->where('activity_type', 'Quiz')->get();

        // Missing = activities for this subject not submitted by this student
        $allActivitiesQuery = Activity::where('subject_id', $subjectId);
        if ($activityType) $allActivitiesQuery->where('type', $activityType);
        $allActivities = $allActivitiesQuery->pluck('id');
        $submitted     = (clone $subsQuery)->pluck('activity_id');
        $missing       = Activity::whereIn('id', $allActivities->diff($submitted)->all())
            ->select('id', 'title', 'type', 'due_date')
            ->get();

        $allScores = (clone $subsQuery)->whereNotNull('score')->pluck('score');

        return response()->json([
            'teacher' => ['id' => $teacher->id, 'name' => $teacher->name],
            'student' => ['id' => $student->id, 'name' => $studentName],
            'subject' => ['id' => $subject->id, 'name' => $subject->name, 'grade_level' => $subject->grade_level],
            'date_from' => $from?->format('Y-m-d'),
            'date_to'   => $to?->format('Y-m-d'),
            'activity_type' => $activityType,
            'lessons'   => [
                'viewed'     => $views,
                'downloaded' => $downloads,
            ],
            'assignments_submitted' => $assignments->where('status', 'Submitted')->count(),
            'tasks_completed'       => $tasks->where('status', 'Submitted')->count(),
            'quizzes_taken'         => $quizzes->where('status', 'Submitted')->count(),
            'scores'                => [
                'quiz_avg'       => $this->avgRound($quizzes->pluck('score')),
                'assignment_avg' => $this->avgRound($assignments->pluck('score')),
                'task_avg'       => $this->avgRound($tasks->pluck('score')),
                'overall_avg'    => $this->avgRound($allScores),
            ],
            'missing' => $missing->map(fn ($a) => [
                'id'       => $a->id,
                'title'    => $a->title,
                'type'     => $a->type,
                'due_date' => $a->due_date?->format('M j, Y'),
            ])->values(),
            'completion_status' => $this->completionLabel($assignments->count() + $tasks->count() + $quizzes->count()),
            'remarks'           => $this->studentRemarks($allScores->avg(), $allScores->count()),
        ]);
    }

    /* ═══════════════════════════════════════════════════════════════ *
     *  6. EXPORT (PDF or Word)                                         *
     * ═══════════════════════════════════════════════════════════════ */
    public function export(Request $request)
    {
        $reportType = $request->string('report_type')->toString();
        $format     = $request->string('format')->toString() ?: 'pdf';

        $data = match ($reportType) {
            'student_lesson_activity' => $this->studentLessonActivity($request)->getData(true),
            'weekly_activity'          => $this->weeklyActivity($request)->getData(true),
            'subject_report'           => $this->subjectReport($request)->getData(true),
            'student_report'           => $this->studentReport($request)->getData(true),
            'task_report'              => $this->taskReport($request)->getData(true),
            default                    => abort(422, 'Unknown report_type'),
        };

        $filename = "{$reportType}_" . now()->format('Ymd_His');

        if ($format === 'word') {
            return $this->exportWord($reportType, $data, $filename);
        }
        return $this->exportPdf($reportType, $data, $filename);
    }

    protected function exportPdf(string $reportType, array $data, string $filename)
    {
        $pdf = Pdf::loadView('reports.export-pdf', [
            'report_type' => $reportType,
            'data'        => $data,
            'generated_at'=> now()->format('M j, Y h:i A'),
        ])->setPaper('a4', 'portrait');

        return $pdf->download("{$filename}.pdf");
    }

    protected function exportWord(string $reportType, array $data, string $filename)
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('StudyNest — Teacher Report', ['bold' => true, 'size' => 18], ['alignment' => Jc::CENTER]);
        $section->addText($this->prettyReportTitle($reportType), ['bold' => true, 'size' => 14], ['alignment' => Jc::CENTER]);
        $section->addText('Generated: ' . now()->format('M j, Y h:i A'), ['italic' => true, 'size' => 10], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        if (!empty($data['teacher']['name'])) {
            $section->addText('Teacher: ' . $data['teacher']['name'], ['bold' => true, 'size' => 11]);
        }
        if (!empty($data['subject']['name'])) {
            $section->addText('Subject: ' . $data['subject']['name'] . ' — ' . ($data['subject']['grade_level'] ?? ''), ['size' => 11]);
        }
        if (!empty($data['student']['name'])) {
            $section->addText('Student: ' . $data['student']['name'], ['size' => 11]);
        }
        if (!empty($data['activity']['title'])) {
            $section->addText('Task: ' . $data['activity']['title'] . ' — ' . ($data['activity']['type'] ?? ''), ['size' => 11]);
        }
        if (!empty($data['activity_type'])) {
            $section->addText('Task type: ' . $data['activity_type'], ['size' => 11]);
        }
        if (!empty($data['date_from']) || !empty($data['date_to'])) {
            $section->addText('Date range: ' . ($data['date_from'] ?? '—') . '  to  ' . ($data['date_to'] ?? '—'), ['size' => 11]);
        }
        $section->addTextBreak(1);

        $rows = $this->flattenForExport($reportType, $data);
        if (!empty($rows['headers'])) {
            $tableStyle = ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80];
            $phpWord->addTableStyle('reportTable', $tableStyle, ['bgColor' => 'EDE9FE']);
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

        // Clear any accidental output (PHP warnings, stray whitespace) that
        // would prepend to the binary and corrupt the .docx file.
        if (ob_get_level()) {
            ob_end_clean();
        }

        return response()->download($tempPath, "{$filename}.docx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }

    /* ─── Helpers ─────────────────────────────────────────────────── */

    /** Returns enrolled students for a subject as objects with: id, name, user_id. */
    protected function enrolledStudents(int $subjectId, ?int $singleStudentId = null)
    {
        $q = DB::table('subject_student')
            ->join('students', 'students.id', '=', 'subject_student.student_id')
            ->leftJoin('users', 'users.id', '=', 'students.user_id')
            ->where('subject_student.subject_id', $subjectId)
            ->select(
                'students.id',
                'students.user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', students.first_name, students.middle_name, students.last_name)), ''), users.name, 'Student') AS name")
            );

        if ($singleStudentId) {
            $q->where('students.id', $singleStudentId);
        }

        return $q->orderBy('name')->get();
    }

    protected function ensureOwnsSubject(int $teacherId, ?int $subjectId): void
    {
        if (!$subjectId) {
            abort(422, 'subject_id is required.');
        }
        $owns = DB::table('subject_teacher')
            ->where('subject_id', $subjectId)
            ->where('teacher_id', $teacherId)
            ->exists();

        abort_unless($owns, Response::HTTP_FORBIDDEN, 'You are not assigned to this subject.');
    }

    protected function buildRemarks(int $views, int $downloads, $assignment, $task, $quiz): string
    {
        $bits = [];
        if ($views === 0 && $downloads === 0) $bits[] = 'No lesson activity';
        if ($assignment?->status === 'Missing') $bits[] = 'Missing assignment';
        if ($task?->status === 'Missing')       $bits[] = 'Missing task';
        if ($quiz?->status === 'Missing')       $bits[] = 'Missing quiz';
        if (empty($bits))                       return 'On track';
        return implode(' · ', $bits);
    }

    protected function studentRemarks($avgScore, int $totalSubs): string
    {
        if ($totalSubs === 0) return 'No submissions yet';
        $score = (float) $avgScore;
        if ($score >= 90) return 'Excellent performance';
        if ($score >= 80) return 'Good performance';
        if ($score >= 75) return 'Satisfactory';
        if ($score >  0)  return 'Needs improvement';
        return 'Pending grades';
    }

    protected function completionLabel(int $count): string
    {
        if ($count === 0)  return 'Not started';
        if ($count < 3)    return 'In progress';
        return 'Completed';
    }

    protected function avgRound($collection)
    {
        $avg = collect($collection)->filter(fn ($value) => $value !== null && $value !== '')->avg();
        return $avg !== null ? round((float) $avg, 2) : '—';
    }

    protected function prettyActivityType(string $key): string
    {
        return match ($key) {
            'lesson_uploaded'    => 'Lesson',
            'lesson_published'   => 'Lesson',
            'lesson_drafted'     => 'Lesson',
            'assignment_created' => 'Assignment',
            'task_created'       => 'Activity',
            'quiz_created'       => 'Quiz',
            default              => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    protected function prettyReportTitle(string $type): string
    {
        return match ($type) {
            'student_lesson_activity' => 'Student Lesson & Activity Report',
            'weekly_activity'          => 'Weekly Teacher Activity Report',
            'subject_report'           => 'Subject Report',
            'student_report'           => 'Student Report',
            'task_report'              => 'Task Scores Report',
            default                    => 'Report',
        };
    }

    protected function flattenForExport(string $type, array $data): array
    {
        if ($type === 'student_lesson_activity') {
            $headers = ['Student','Viewed','Downloaded','Assignment','Task','Quiz','Score','Date','Remarks'];
            $rows = collect($data['rows'] ?? [])->map(fn ($r) => [
                $r['student_name'], $r['lessons_viewed'], $r['lessons_downloaded'],
                $r['assignment_status'], $r['task_status'], $r['quiz_status'],
                $r['score'], $r['date_completed'], $r['remarks'],
            ])->all();
            return compact('headers', 'rows');
        }
        if ($type === 'weekly_activity') {
            $headers = ['Subject','Day','Type','Title','Description','Status','Created'];
            $rows = [];
            foreach (($data['subjects'] ?? []) as $subj) {
                foreach ($subj['days'] as $day) {
                    if (empty($day['entries'])) {
                        $rows[] = [$subj['subject'], $day['day'], '—', 'No activity created.', '', '', ''];
                        continue;
                    }
                    foreach ($day['entries'] as $e) {
                        $rows[] = [$subj['subject'], $day['day'], $e['type'], $e['title'], $e['description'], $e['status'], $e['created_date']];
                    }
                }
            }
            return compact('headers', 'rows');
        }
        if ($type === 'subject_report') {
            $headers = ['Student','Lessons','Assignments','Tasks','Quizzes','Avg Score','Status','Remarks'];
            $rows = collect($data['students'] ?? [])->map(fn ($r) => [
                $r['student_name'], $r['lessons_accessed'], $r['assignments_submitted'],
                $r['tasks_completed'], $r['quizzes_taken'], $r['average_score'],
                $r['completion_status'], $r['remarks'],
            ])->all();
            return compact('headers', 'rows');
        }
        if ($type === 'task_report') {
            $headers = ['Student', 'Status', 'Score', 'Submitted'];
            $totalPoints = $data['activity']['total_points'] ?? null;
            $rows = collect($data['rows'] ?? [])->map(fn ($row) => [
                $row['student_name'],
                $row['status'],
                $row['score'] === null ? '—' : $row['score'] . ($totalPoints ? " / {$totalPoints}" : ''),
                $row['submitted_at'] ?? '—',
            ])->all();
            return compact('headers', 'rows');
        }
        if ($type === 'student_report') {
            $headers = ['Metric','Value'];
            $rows = [
                ['Lessons Viewed', $data['lessons']['viewed']      ?? 0],
                ['Lessons Downloaded', $data['lessons']['downloaded'] ?? 0],
                ['Assignments Submitted', $data['assignments_submitted'] ?? 0],
                ['Tasks Completed',  $data['tasks_completed']  ?? 0],
                ['Quizzes Taken',    $data['quizzes_taken']    ?? 0],
                ['Quiz Avg',         $data['scores']['quiz_avg']       ?? '—'],
                ['Assignment Avg',   $data['scores']['assignment_avg'] ?? '—'],
                ['Task Avg',         $data['scores']['task_avg']       ?? '—'],
                ['Overall Avg',      $data['scores']['overall_avg']    ?? '—'],
                ['Completion',       $data['completion_status']        ?? '—'],
                ['Remarks',          $data['remarks']                  ?? '—'],
                ['Missing Activities', count($data['missing'] ?? [])],
            ];
            return compact('headers', 'rows');
        }
        return ['headers' => [], 'rows' => []];
    }
}