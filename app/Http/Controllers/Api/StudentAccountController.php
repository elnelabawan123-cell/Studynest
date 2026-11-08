<?php

namespace App\Http\Controllers\Api;

use App\Mail\StudentCredentialsMail;
use App\Models\SchoolYear;
use App\Models\Section;
use App\Models\AdminSetting;
use App\Models\Student;
use App\Models\User;
use App\Traits\GeneratesStudentUsername;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StudentAccountController extends BaseApiController
{
    use GeneratesStudentUsername;

    // ── Username generator: lastname.SDA ──────────────────────
    // NOTE: kept as-is for backward compatibility (nothing else changed
    // about it) — it now simply delegates to the shared trait so the
    // bulk-upload flow generates usernames identically.
    private function generateUsername(string $lastName): string
    {
        return $this->generateStudentUsername($lastName);
    }

    /**
     * Send login credentials to a student's email.
     * Never throws — the account is always considered created successfully
     * either way. On failure it logs a warning; the admin can retry delivery
     * by resetting the password from the central Accounts page.
     *
     * @return array{sent: bool, error: ?string}
     */
    private function sendCredentialsEmail(User $user, string $fullName, string $studentNumber, string $email, string $password): array
    {
        try {
            Mail::to($email)->send(new StudentCredentialsMail($fullName, $studentNumber, $password));
            return ['sent' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning("Failed to email student credentials to {$email} (user #{$user->id}): " . $e->getMessage());
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Shared row mapper ─────────────────────────────────────
    private function mapUser(User $u): array
    {
        $p  = $u->studentProfile;
        $sy = $p?->schoolYear;
        return [
            'id'             => $u->id,
            'name'           => $p?->full_name ?? $u->name,
            'first_name'     => $p?->first_name,
            'middle_name'    => $p?->middle_name,
            'last_name'      => $p?->last_name,
            'suffix'         => $p?->suffix,
            'username'       => $u->username,
            'email'          => $u->email,
            'role'           => $u->role,
            'is_first_login' => $u->is_first_login,
            'created_at'     => $u->created_at?->format('M d, Y'),
            'archived_at'    => $u->archived_at?->format('M d, Y'),
            'student_id'     => $p?->id,
            'student_number' => $p?->student_number ?? '—',
            'grade'          => $p?->grade          ?? '—',
            'section_id'     => $p?->section_id,
            'section_name'   => $p?->section?->name ?? '—',
            'gpa'            => $p?->gpa            ?? 0,
            'status'         => $p?->status         ?? 'Active',
            'school_year'    => $sy ? ['id' => $sy->id, 'label' => $sy->label] : null,
        ];
    }

    /** GET /api/student-accounts */
   public function index(Request $request): JsonResponse
{
    $query = User::where('role', 'student');
 
    if ($search = $request->query('search')) {
        $query->where(function ($q) use ($search) {
            $q->where('name',      'like', "%{$search}%")
              ->orWhere('username', 'like', "%{$search}%")
              ->orWhere('email',    'like', "%{$search}%")
              ->orWhereHas('studentProfile', fn ($sq) =>
                  $sq->where('student_number', 'like', "%{$search}%")
              );
        });
    }
 
    // archived = has archived_at set
    // active   = no archived_at
    $statusFilter = $request->query('status', 'active');
    if ($statusFilter === 'archived') {
        $query->whereNotNull('archived_at');
    } elseif ($statusFilter === 'active') {
        $query->whereNull('archived_at');
    }
    // 'all' → no filter

    // Grade / section filters live on the related student profile.
    if ($grade = $request->query('grade')) {
        $query->whereHas('studentProfile', fn ($sq) => $sq->where('grade', $grade));
    }
    if ($sectionId = $request->query('section_id')) {
        $query->whereHas('studentProfile', fn ($sq) => $sq->where('section_id', $sectionId));
    }

    // Sort newest (created_at desc) or oldest (created_at asc)
    $sort = $request->query('sort', 'newest');
    $query->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc');

    $perPage = (int) $request->query('per_page', 10);
    $students = $query
        ->with(['studentProfile.section', 'studentProfile.schoolYear'])
        ->paginate($perPage)
        ->through(fn ($u) => $this->mapUser($u));
 
    return $this->success($students);
}

    /**
     * POST /api/student-accounts
     * Auto-assigns student to the current active school year.
     *
     * NEW: accepts an `email` field. Credentials are emailed to the
     * student automatically after the account is created. If email
     * delivery fails, the account is still created — the response
     * simply reports `email_status: 'failed'` so the admin UI can warn.
     */
    public function store(Request $request): JsonResponse
    {
        $autoAssignSection = (bool) AdminSetting::get('auto_section_assignment', false);
        $data = $request->validate([
            'student_number' => [
                'required', 'string', 'max:20',
                'regex:/^[A-Za-z0-9\-]+$/',
                'unique:students,student_number',
            ],
            'first_name'  => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name'   => 'required|string|max:100',
            'suffix'      => 'nullable|string|max:20',
            'grade'       => 'required|string|max:50',
            'section_id'  => $autoAssignSection ? 'sometimes|nullable|exists:sections,id' : 'required|exists:sections,id',
            'email'       => [
                'required', 'string', 'email', 'max:255',
                'unique:students,email',
                'unique:users,email',
            ],
        ], [
            'student_number.required'  => 'Student ID is required.',
            'student_number.unique'    => 'This Student ID is already in use.',
            'student_number.max'       => 'Student number is too long. Please enter a valid student number only.',
            'student_number.regex'     => 'Student number can only contain letters, numbers, and hyphens.',
            'first_name.required'      => 'First name is required.',
            'last_name.required'       => 'Last name is required.',
            'grade.required'           => 'Grade level is required.',
            'section_id.required'      => 'Section is required.',
            'section_id.exists'        => 'Selected section is invalid.',
            'email.required'           => 'Email is required.',
            'email.email'              => 'Please enter a valid email address.',
            'email.unique'             => 'This email address is already in use.',
        ]);

        if ($autoAssignSection) {
            $section = Section::leastPopulatedForGrade($data['grade']);
            if (!$section) {
                return $this->error("No active section is available for {$data['grade']}. Create or activate a section before adding students.", 422);
            }
        } else {
            $section = Section::findOrFail($data['section_id']);
            if ($section->grade_level !== $data['grade']) {
                return $this->error("Section \"{$section->name}\" does not belong to {$data['grade']}.", 422);
            }
        }

        // Get the active school year
        $schoolYear = SchoolYear::active();

        DB::beginTransaction();
        try {
            $fullName        = trim(implode(' ', array_filter([
                $data['first_name'], $data['middle_name'] ?? null,
                $data['last_name'],  $data['suffix']      ?? null,
            ])));
            $username        = $this->generateUsername($data['last_name']);
            $email           = strtolower(trim($data['email']));
            $defaultPassword = 'sdapass123#';

            $user = User::create([
                'name'           => $fullName,
                'email'          => $email,
                'username'       => $username,
                'password'       => Hash::make($defaultPassword),
                'role'           => 'student',
                'is_first_login' => true,
            ]);

            $student = Student::create([
                'user_id'        => $user->id,
                'student_number' => strtoupper(trim($data['student_number'])),
                'first_name'     => $data['first_name'],
                'middle_name'    => $data['middle_name'] ?? null,
                'last_name'      => $data['last_name'],
                'suffix'         => $data['suffix']      ?? null,
                'email'          => $email,
                'grade'          => $data['grade'],
                'section_id'     => $section->id,
                'school_year_id' => $schoolYear?->id,  // ← assigned to active school year
                'gpa'            => 0,
                'status'         => 'Active',
            ]);

            DB::commit();

            // ── Send credentials by email (outside the DB transaction) ──
            $mailResult = $this->sendCredentialsEmail($user, $fullName, $student->student_number, $email, $defaultPassword);

            return $this->success([
                'id'               => $user->id,
                'student_id'       => $student->id,
                'student_number'   => $student->student_number,
                'name'             => $fullName,
                'username'         => $username,
                'email'            => $email,
                'grade'            => $student->grade,
                'section_name'     => $section->name,
                'school_year'      => $schoolYear ? ['id' => $schoolYear->id, 'label' => $schoolYear->label] : null,
                'status'           => 'Active',
                'is_first_login'   => true,
                'default_password' => $defaultPassword,
                'email_status'     => $mailResult['sent'] ? 'sent' : 'failed',
                'email_error'      => $mailResult['error'],
            ], $mailResult['sent']
                ? 'Student account created successfully. Credentials emailed to the student.'
                : 'Student account created successfully, but the credentials email could not be sent. Please share the credentials manually.',
            201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to create student account: ' . $e->getMessage());
        }
    }

    /** PUT /api/student-accounts/{id} — only grade/section editable */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::where('role', 'student')->findOrFail($id);
        $data = $request->validate([
            'grade'      => 'sometimes|string|max:50',
            'section_id' => 'sometimes|exists:sections,id',
        ]);

        if (isset($data['section_id'])) {
            $profile = $user->studentProfile;
            $grade   = $data['grade'] ?? $profile?->grade;
            $section = Section::findOrFail($data['section_id']);
            if ($section->grade_level !== $grade) {
                return $this->error("Section \"{$section->name}\" does not belong to {$grade}.", 422);
            }
        }

        DB::beginTransaction();
        try {
            if ($profile = $user->studentProfile) {
                $profile->update(array_filter($data));
            }
            DB::commit();
            return $this->success(null, 'Student updated successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to update: ' . $e->getMessage());
        }
    }

    /**
     * PATCH /api/student-accounts/{id}/status
     * Only Active or Inactive. Inactive → auto-archive.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $user = User::where('role', 'student')->findOrFail($id);
        $data = $request->validate([
            'status' => 'required|in:Active,Inactive',  // Archive removed — auto only
        ]);

        DB::beginTransaction();
        try {
            if ($profile = $user->studentProfile) {
                $profile->update(['status' => $data['status']]);
            }

            // Inactive → archive automatically
            $user->update([
                'archived_at' => $data['status'] === 'Inactive' ? Carbon::now() : null,
            ]);

            DB::commit();
            return $this->success(null, 'Student status updated.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to update status: ' . $e->getMessage());
        }
    }

    /** POST /api/student-accounts/{id}/restore */
    public function restore(int $id): JsonResponse
    {
        $user = User::where('role', 'student')->findOrFail($id);
        DB::beginTransaction();
        try {
            $user->update(['archived_at' => null]);
            if ($profile = $user->studentProfile) {
                $profile->update(['status' => 'Active']);
            }
            DB::commit();
            return $this->success(null, 'Student restored to Active.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to restore: ' . $e->getMessage());
        }
    }

    /** POST /api/student-accounts/{id}/reset-password */
    public function resetPassword(int $id): JsonResponse
    {
        $user    = User::where('role', 'student')->findOrFail($id);
        $profile = $user->studentProfile;
        if (! $profile) {
            return $this->error('Student profile not found.', 404);
        }

        $defaultPassword = 'sdapass123#';
        $user->update([
            'password'       => Hash::make($defaultPassword),
            'is_first_login' => true,
        ]);
        $user->tokens()->delete();

        // Notify the student, same as the teacher reset flow. Never blocks
        // the reset itself — on failure it just logs a warning, same as
        // on account creation.
        $mailResult = $this->sendCredentialsEmail($user, $profile->full_name ?? $user->name, $profile->student_number, $user->email, $defaultPassword);

        return $this->success([
            'student_number'   => $profile->student_number,
            'username'         => $user->username,
            'default_password' => $defaultPassword,
            'email_status'     => $mailResult['sent'] ? 'sent' : 'failed',
            'email_error'      => $mailResult['error'],
        ], $mailResult['sent']
            ? 'Password reset. New credentials emailed to the student.'
            : 'Password reset, but the credentials email could not be sent. Please share the credentials manually.'
        );
    }
}