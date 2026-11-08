<?php

namespace App\Http\Controllers\Api;

use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Student;
use App\Models\Section;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\AdminSetting;
use App\Services\AdminAlertMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends BaseApiController
{
    const GRADES = [
        'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6',
    ];

    /** GET /api/admin/subjects */
    public function index(Request $request): JsonResponse
    {
        $query = Subject::with('teachers');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhereHas('teachers', fn ($tq) => $tq->where('teachers.name', 'like', "%{$search}%"));
            });
        }

        if ($grade = $request->query('grade')) {
            $query->where('grade_level', $grade);
        }

        // Filter by status (active = not Inactive)
        $statusParam = $request->query('status', 'active');
        $dbStatus = match (strtolower($statusParam)) {
            'archived', 'inactive' => 'Inactive',
            'all'                   => null,
            default                 => 'Active',
        };
        if ($dbStatus !== null) {
            $query->where('status', $dbStatus);
        }

        $sort = $request->query('sort');
        if ($sort === 'oldest') {
            $query->orderBy('created_at', 'asc');
        } elseif ($sort === 'newest') {
            $query->orderBy('created_at', 'desc');
        } else {
            $query->orderBy('grade_level')->orderBy('name');
        }

        $subjects = $query->get()->map(fn ($s) => [
            'id'             => $s->id,
            'code'           => $s->code,
            'name'           => $s->name,
            'grade_level'    => $s->grade_level,
            'status'         => $s->status ?? 'Active',
            'teachers_count' => $s->teachers->count(),
            'teachers'       => $s->teachers->map(fn ($t) => [
                'id'    => $t->id,
                'name'  => $t->name,
                'email' => $t->email,
            ]),
            'sections' => Section::where('grade_level', $s->grade_level)
                ->where('is_active', true)
                ->get(['id', 'name'])
                ->toArray(),
        ]);

        return $this->success($subjects);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code'        => 'required|string|max:20',
            'name'        => 'required|string|max:255',
            'grade_level' => 'required|in:' . implode(',', self::GRADES),
        ], [
            'grade_level.in' => 'Grade level must be one of: ' . implode(', ', self::GRADES),
        ]);

        $name = trim((string) $request->input('name'));
        $normalizedName = mb_strtolower($name, 'UTF-8');
        if (Subject::where('grade_level', $request->grade_level)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->exists()) {
            return $this->error("A subject named \"{$name}\" already exists for {$request->grade_level}.", 422);
        }

        // Allow same code in different grade levels, but not same code+grade combo
        $exists = Subject::where('code', $request->code)
            ->where('grade_level', $request->grade_level)
            ->exists();

        if ($exists) {
            return $this->error(
                "Subject code \"{$request->code}\" already exists for {$request->grade_level}.",
                422
            );
        }

        $data = $request->only(['code', 'name', 'grade_level']);

        $subject = Subject::create([
            'code'        => $data['code'],
            'name'        => $name,
            'grade_level' => $data['grade_level'],
            'units'       => 1,
            'status'      => 'Active',
        ]);

        return $this->success($subject, 'Subject added successfully.', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $subject = Subject::findOrFail($id);

        $data = $request->validate([
            'code'        => "sometimes|string|max:20|unique:subjects,code,{$id}",
            'name'        => 'sometimes|string|max:255',
            'grade_level' => 'sometimes|in:' . implode(',', self::GRADES),
        ]);

        if (array_key_exists('name', $data) || array_key_exists('grade_level', $data)) {
            $name = trim($data['name'] ?? $subject->getRawOriginal('name'));
            $grade = $data['grade_level'] ?? $subject->getRawOriginal('grade_level');
            $normalizedName = mb_strtolower($name, 'UTF-8');
            if (Subject::where('grade_level', $grade)
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
                ->where('id', '!=', $id)
                ->exists()) {
                return $this->error("A subject named \"{$name}\" already exists for {$grade}.", 422);
            }
            $data['name'] = $name;
        }

        $subject->update($data);
        return $this->success($subject, 'Subject updated successfully.');
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $data    = $request->validate(['status' => 'required|in:Active,Inactive']);

        $subject->update([
            'status'      => $data['status'],
            'archived_at' => $data['status'] === 'Inactive' ? now() : null,
        ]);

        $msg = $data['status'] === 'Inactive'
            ? "Subject \"{$subject->name}\" is now Inactive and moved to Archive."
            : "Subject \"{$subject->name}\" is now Active.";

        return $this->success(null, $msg);
    }

    /** POST /api/admin/subjects/{id}/restore */
    public function restore(int $id): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $subject->update([
            'status'      => 'Active',
            'archived_at' => null,
        ]);
        return $this->success(null, "Subject \"{$subject->name}\" restored to Active.");
    }


    // â”€â”€ Teacher assignment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function assignTeacher(Request $request, int $id): JsonResponse
    {
        $data    = $request->validate(['teacher_id' => 'required|exists:teachers,id']);
        return DB::transaction(function () use ($id, $data): JsonResponse {
            $subject = Subject::whereKey($id)->lockForUpdate()->firstOrFail();
            $teacher = Teacher::findOrFail($data['teacher_id']);
            $assignedTeacher = $subject->teachers()->first();

            if ($assignedTeacher) {
                if ($assignedTeacher->id === $teacher->id) {
                    return $this->error("{$teacher->name} is already assigned to this subject.", 422);
                }

                return $this->error(
                    "This subject is already assigned to {$assignedTeacher->name}. Unassign that teacher before assigning another.",
                    422
                );
            }

            $subject->teachers()->attach($teacher->id);
            return $this->success(null, "Teacher \"{$teacher->name}\" assigned to \"{$subject->name}\".");
        });
    }

    public function unassignTeacher(int $id, int $teacherId): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $teacher = Teacher::findOrFail($teacherId);
        $subject->teachers()->detach($teacherId);
        return $this->success(null, "Teacher \"{$teacher->name}\" removed from \"{$subject->name}\".");
    }

    // â”€â”€ Teacher: own subjects â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function mySubjects(Request $request): JsonResponse
    {
        $user    = $request->user();
        $teacher = Teacher::where('email', $user->email)->first();

        if (! $teacher) {
            return $this->error('Teacher profile not found.', 404);
        }

        $subjects = $teacher->subjects()
            ->where('status', 'Active')
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'id'             => $s->id,
                'code'           => $s->code,
                'name'           => $s->name,
                'grade_level'    => $s->grade_level,
                'status'         => $s->status,
                'students_count' => $s->students()->count(),
            ]);

        return $this->success($subjects);
    }

    // â”€â”€ Student: own enrolled subjects â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function studentSubjects(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        if (! $studentProfile) {
            return $this->error('Student profile not found.', 403);
        }

        $subjects = Subject::where('status', 'Active')
            ->whereHas('students', function ($q) use ($studentProfile) {
                return $q->where('students.id', $studentProfile->id);
            })
            ->orderBy('name')
            ->get()
            ->map(function ($s) {
                return [
                    'id' => $s->id,
                    'code' => $s->code,
                    'name' => $s->name,
                    'grade_level' => $s->grade_level,
                    'status' => $s->status,
                ];
            });

        return $this->success($subjects);
    }

    public function availableForStudent(Request $request): JsonResponse
    {
        if (!AdminSetting::get('student_self_enrollment', false)) {
            return $this->error('Student self-enrollment is currently disabled.', 403);
        }

        $student = $request->user()->studentProfile;
        if (!$student || $student->status !== 'Active') {
            return $this->error('Active student profile not found.', 403);
        }

        $subjects = Subject::query()
            ->where('status', 'Active')
            ->where('grade_level', $student->grade)
            ->whereDoesntHave('students', fn ($query) => $query->where('students.id', $student->id))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'grade_level']);

        return $this->success($subjects);
    }

    public function enrollStudent(Request $request, int $id, AdminAlertMailer $alerts): JsonResponse
    {
        if (!AdminSetting::get('student_self_enrollment', false)) {
            return $this->error('Student self-enrollment is currently disabled.', 403);
        }

        $student = $request->user()->studentProfile;
        if (!$student || $student->status !== 'Active') {
            return $this->error('Active student profile not found.', 403);
        }

        $subject = DB::transaction(function () use ($student, $id): Subject {
            $lockedStudent = Student::query()->lockForUpdate()->findOrFail($student->id);
            if ($lockedStudent->status !== 'Active') {
                abort(403, 'Active student profile not found.');
            }

            $subject = Subject::query()->lockForUpdate()->find($id);
            if (!$subject || $subject->status !== 'Active') {
                abort(404, 'Active subject not found.');
            }
            if ($subject->grade_level !== $lockedStudent->grade) {
                abort(403, 'You can only enroll in subjects for your grade level.');
            }
            if ($subject->students()->where('students.id', $lockedStudent->id)->exists()) {
                abort(409, 'You are already enrolled in this subject.');
            }

            $subject->students()->attach($lockedStudent->id);
            return $subject;
        });

        $name = $student->full_name;
        $mail = $alerts->send(
            'Student self-enrollment',
            "A student enrolled in a subject.\n\nStudent: {$name} ({$student->student_number})\n"
            . "Grade: {$student->grade}\nSubject: {$subject->name} ({$subject->code})"
        );
        ActivityLog::record(
            ActivityLog::ACTION_ENROLL_STUDENT,
            'Subjects',
            "{$name} enrolled in {$subject->name}.",
            $request->user()->id,
            'student',
            $request->ip()
        );
        AuditLog::record(
            'created',
            'subject_student',
            $student->id,
            null,
            ['subject_id' => $subject->id],
            "{$name} enrolled in {$subject->name}."
        );

        return $this->success([
            'subject' => [
                'id' => $subject->id,
                'code' => $subject->code,
                'name' => $subject->name,
                'grade_level' => $subject->grade_level,
            ],
            'email_status' => $mail['status'],
        ], 'Enrolled in subject successfully.');
    }

    // â”€â”€ Student enrollment â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    public function enrolledStudents(Request $request, int $id): JsonResponse
    {
        $subject  = Subject::findOrFail($id);
        $students = $subject->students()
            ->with('section')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($s) => [
                'id'           => $s->id,
                'name'         => $s->full_name,
                'first_name'   => $s->first_name,
                'last_name'    => $s->last_name,
                'email'        => $s->email,
                'grade'        => $s->grade,
                'section_name' => $s->section?->name ?? 'â€”',
                'gpa'          => $s->gpa,
                'status'       => $s->status,
            ]);
        return $this->success($students);
    }

    /**
     * GET /api/teacher/subjects/{id}/available-students
     *
     * IMPORTANT: Only returns students whose grade matches the subject's grade_level.
     * This prevents teachers from adding students from wrong grade levels.
     */
    public function availableStudents(Request $request, int $id): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $query   = Student::query()->with('section');

        // â”€â”€ ALWAYS filter by subject's grade level â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
        $query->where('grade', $subject->grade_level);

        // Additional optional filters
        if ($sid = $request->query('section_id')) {
            $query->where('section_id', $sid);
        }

        if ($s = $request->query('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                  ->orWhere('last_name',  'like', "%{$s}%");
            });
        }

        // Only active students
        $query->where('status', 'Active');

        $enrolled = $subject->students()->pluck('students.id')->toArray();

        $students = $query->orderBy('last_name')->get()->map(fn ($s) => [
            'id'           => $s->id,
            'name'         => $s->full_name,
            'email'        => $s->email,
            'grade'        => $s->grade,
            'section_id'   => $s->section_id,
            'section_name' => $s->section?->name ?? 'â€”',
            'gpa'          => $s->gpa,
            'status'       => $s->status,
            'is_enrolled'  => in_array($s->id, $enrolled),
        ]);

        return $this->success([
            'students'    => $students,
            'grade_level' => $subject->grade_level,
        ]);
    }

    /**
     * POST /api/teacher/subjects/{id}/enroll-students
     *
     * Writes ActivityLog + AuditLog rows per newly-enrolled student.
     *  - ActivityLog uses a friendly "You enrolled X..." message â†’ shown in teacher's bell.
     *  - AuditLog uses a formal "Teacher X enrolled student Y (ID:...)..." message â†’ for admin forensics.
     */
    public function enrollStudents(Request $request, int $id): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $data    = $request->validate([
            'student_ids'   => 'required|array|min:1',
            'student_ids.*' => 'exists:students,id',
        ]);

        // Validate all students match the subject's grade level
        $wrongGrade = Student::whereIn('id', $data['student_ids'])
            ->where('grade', '!=', $subject->grade_level)
            ->pluck('first_name');

        if ($wrongGrade->isNotEmpty()) {
            return $this->error(
                "Some students do not match the grade level of this subject ({$subject->grade_level}). Only {$subject->grade_level} students can be enrolled here.",
                422
            );
        }

        // Attach â€” capture which IDs were ACTUALLY newly attached
        // (skips students already enrolled, so we don't log no-ops)
        $result        = $subject->students()->syncWithoutDetaching($data['student_ids']);
        $newlyAttached = $result['attached'] ?? [];

        $user = $request->user();
        foreach ($newlyAttached as $sid) {
            $student = Student::find($sid);
            if (! $student) continue;

            // Friendly text for the teacher's notification bell + Activity Log
            $notifText = "You enrolled {$student->full_name} in {$subject->name} ({$subject->grade_level}).";

            // Formal text for the admin Audit Log (keeps IDs for forensics)
            $auditText = "Teacher \"{$user->name}\" enrolled student \"{$student->full_name}\" (ID: {$student->id}) into subject \"{$subject->name}\" ({$subject->grade_level}).";

            // â”€â”€ Activity Log â”€ picked up by NotificationController â†’ bell
            ActivityLog::create([
                'user_id'     => $user->id,
                'user_role'   => 'teacher',
                'action'      => 'enroll_student',
                'module'      => 'teacher_students',
                'description' => $notifText,
            ]);

            // â”€â”€ Audit Log â”€ accountability trail (admin-facing, formal)
            AuditLog::create([
                'user_id'     => $user->id,
                'user_role'   => 'teacher',
                'action'      => 'INSERT',
                'table_name'  => 'student_subject', // Laravel default pivot â€” verify in Subject/Student model
                'description' => $auditText,
                'old_values'  => null,
                'new_values'  => json_encode([
                    'subject_id' => $subject->id,
                    'student_id' => $sid,
                ]),
            ]);
        }

        $count   = count($newlyAttached);
        $skipped = count($data['student_ids']) - $count;

        $msg = $count > 0
            ? "{$count} student(s) enrolled in {$subject->name}."
                . ($skipped > 0 ? " ({$skipped} were already enrolled.)" : '')
            : "All selected students were already enrolled.";

        return $this->success(['enrolled' => $count, 'skipped' => $skipped], $msg);
    }

    /**
     * DELETE /api/teacher/subjects/{id}/unenroll-student/{studentId}
     *
     * Accepts optional remarks (reason) in the request body.
     *  - ActivityLog uses friendly "You removed X..." message â†’ shown in teacher's bell.
     *  - AuditLog uses formal "Teacher X removed student Y (ID:...)..." message â†’ admin forensics.
     *  - Remarks appear in both messages, and are also stored as a structured field
     *    inside audit log's old_values for searchable accountability.
     */
    public function unenrollStudent(Request $request, int $id, int $studentId): JsonResponse
    {
        $subject = Subject::findOrFail($id);
        $student = Student::findOrFail($studentId);
        $remarks = trim($request->input('remarks', ''));

        $subject->students()->detach($studentId);

        $user = $request->user();

        // Friendly text for the teacher's bell + Activity Log
        $notifText = "You removed {$student->full_name} from {$subject->name}"
            . ($remarks !== '' ? " â€” {$remarks}." : ".");

        // Formal text for the admin Audit Log
        $auditText = "Teacher \"{$user->name}\" removed student \"{$student->full_name}\" (ID: {$student->id}) from subject \"{$subject->name}\""
            . ($remarks !== '' ? ". Reason: {$remarks}" : ".");

        // â”€â”€ Activity Log â”€ picked up by NotificationController â†’ bell
        ActivityLog::create([
            'user_id'     => $user->id,
            'user_role'   => 'teacher',
            'action'      => 'unenroll_student',
            'module'      => 'teacher_students',
            'description' => $notifText,
        ]);

        // â”€â”€ Audit Log â”€ remarks preserved in old_values for accountability
        AuditLog::create([
            'user_id'     => $user->id,
            'user_role'   => 'teacher',
            'action'      => 'DELETE',
            'table_name'  => 'student_subject', // Laravel default pivot â€” verify in Subject/Student model
            'description' => $auditText,
            'old_values'  => json_encode([
                'subject_id' => $subject->id,
                'student_id' => $student->id,
                'remarks'    => $remarks,
            ]),
            'new_values'  => null,
        ]);

        return $this->success(null, "Student \"{$student->full_name}\" removed from \"{$subject->name}\".");
    }

    /** GET /api/admin/subjects/names */
    public function names(): JsonResponse
    {
        return $this->success(Subject::distinct()->pluck('name')->sort()->values());
    }

    /** GET /api/admin/subjects/available-grades */
    public function availableGrades(Request $request): JsonResponse
    {
        $name      = $request->query('name', '');
        $excludeId = $request->query('exclude_id');
        $used = Subject::where('name', $name)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->pluck('grade_level')->toArray();
        $available = array_values(array_diff(self::GRADES, $used));
        return $this->success([
            'all_grades'       => self::GRADES,
            'used_grades'      => $used,
            'available_grades' => $available,
            'all_assigned'     => empty($available),
        ]);
    }
}