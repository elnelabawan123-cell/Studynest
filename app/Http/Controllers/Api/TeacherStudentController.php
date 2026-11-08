<?php

namespace App\Http\Controllers\Api;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Activitylog;
use App\Models\Auditlog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherStudentController extends BaseApiController
{
    /**
     * GET /api/teacher/students
     * Returns ALL students in the system.
     * Teachers see this full list so they can pick which ones to assign.
     */
     public function allStudents(Request $request): JsonResponse
    {
        $query = Student::query()
            ->where('status', 'Active')          // ← hide inactive students
            ->whereHas('user', fn ($q) =>        // ← hide archived users
                $q->whereNull('archived_at')
            );
 
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name',  'like', "%{$search}%")
                  ->orWhere('email',      'like', "%{$search}%")
                  ->orWhere('grade',      'like', "%{$search}%");
            });
        }
 
        $students = $query->orderBy('last_name')
            ->paginate(10)
            ->through(function ($s) use ($request) {
                $teacherProfile = $request->user()->teacherProfile;
                $isAssigned     = $teacherProfile
                    ? $s->teachers()->where('teachers.id', $teacherProfile->id)->exists()
                    : false;
 
                return [
                    'id'          => $s->id,
                    'name'        => $s->full_name,
                    'email'       => $s->email,
                    'grade'       => $s->grade,
                    'section'     => $s->section,
                    'gpa'         => $s->gpa,
                    'status'      => $s->status,
                    'has_account' => (bool) $s->user_id,
                    'is_assigned' => $isAssigned,
                ];
            });
 
        return $this->success($students);
    }

    /**
     * GET /api/teacher/my-students
     * Returns only students assigned to the authenticated teacher.
     */
    public function myStudents(Request $request): JsonResponse
    {
        $teacherProfile = $request->user()->teacherProfile;

        if (! $teacherProfile) {
            return $this->error('Teacher profile not found. Please contact admin.', 404);
        }

        $query = $teacherProfile->students();

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('students.first_name', 'like', "%{$search}%")
                  ->orWhere('students.last_name',  'like', "%{$search}%")
                  ->orWhere('students.email',       'like', "%{$search}%")
                  ->orWhere('students.grade',       'like', "%{$search}%");
            });
        }

        $students = $query->orderBy('students.last_name')
            ->paginate(10)
            ->through(fn ($s) => [
                'id'          => $s->id,
                'name'        => $s->full_name,
                'email'       => $s->email,
                'grade'       => $s->grade,
                'section'     => $s->section,
                'gpa'         => $s->gpa,
                'status'      => $s->status,
                'has_account' => (bool) $s->user_id,
                'is_assigned' => true,
            ]);

        return $this->success($students);
    }

    /**
     * POST /api/teacher/students/{studentId}/assign
     * Authenticated teacher assigns a student to themselves.
     */
    public function assign(Request $request, int $studentId): JsonResponse
    {
        $student        = Student::findOrFail($studentId);
        $teacherProfile = $request->user()->teacherProfile;

        if (! $teacherProfile) {
            return $this->error('Teacher profile not found. Please contact admin.', 404);
        }

        // Prevent duplicate assignment (syncWithoutDetaching keeps others)
        $teacherProfile->students()->syncWithoutDetaching([$studentId]);

        $user        = $request->user();
        $description = "Teacher \"{$user->name}\" enrolled student \"{$student->full_name}\" (ID: {$student->id}) into their class.";

        // ── Activity Log (operational history) ──────────────────────────
        Activitylog::create([
            'user_id'     => $user->id,
            'user_role'   => 'teacher',
            'action'      => 'enroll_student',
            'module'      => 'teacher_students',
            'description' => $description,
        ]);

        // ── Audit Log (accountability / data-change trail) ───────────────
        Auditlog::create([
            'user_id'     => $user->id,
            'user_role'   => 'teacher',
            'action'      => 'INSERT',
            'table_name'  => 'student_teacher',
            'description' => $description,
            'old_values'  => null,
            'new_values'  => json_encode([
                'teacher_id' => $teacherProfile->id,
                'student_id' => $studentId,
            ]),
        ]);

        return $this->success(null, "Student \"{$student->full_name}\" assigned to your class.");
    }

    /**
     * DELETE /api/teacher/students/{studentId}/unassign
     * Authenticated teacher removes a student from their class.
     */
    public function unassign(Request $request, int $studentId): JsonResponse
    {
        $student        = Student::findOrFail($studentId);
        $teacherProfile = $request->user()->teacherProfile;

        if (! $teacherProfile) {
            return $this->error('Teacher profile not found.', 404);
        }

        $teacherProfile->students()->detach($studentId);

        $user        = $request->user();
        $description = "Teacher \"{$user->name}\" removed student \"{$student->full_name}\" (ID: {$student->id}) from their class.";

        // ── Activity Log ──────────────────────────────────────────────────
        Activitylog::create([
            'user_id'     => $user->id,
            'user_role'   => 'teacher',
            'action'      => 'unenroll_student',
            'module'      => 'teacher_students',
            'description' => $description,
        ]);

        // ── Audit Log ─────────────────────────────────────────────────────
        Auditlog::create([
            'user_id'     => $user->id,
            'user_role'   => 'teacher',
            'action'      => 'DELETE',
            'table_name'  => 'student_teacher',
            'description' => $description,
            'old_values'  => json_encode([
                'teacher_id' => $teacherProfile->id,
                'student_id' => $studentId,
            ]),
            'new_values'  => null,
        ]);

        return $this->success(null, "Student \"{$student->full_name}\" removed from your class.");
    }

    /**
     * GET /api/admin/students/{studentId}/teachers
     * Admin: see which teachers a student is assigned to.
     */
    public function studentTeachers(int $studentId): JsonResponse
    {
        $student  = Student::findOrFail($studentId);
        $teachers = $student->teachers()->get()->map(fn ($t) => [
            'id'          => $t->id,
            'name'        => $t->name,
            'email'       => $t->email,
            'subject'     => $t->subject,
            'grade_level' => $t->grade_level,
        ]);

        return $this->success($teachers);
    }
}