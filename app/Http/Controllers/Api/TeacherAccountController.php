<?php

namespace App\Http\Controllers\Api;

use App\Mail\TeacherCredentialsMail;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TeacherAccountController extends BaseApiController
{
    private function generateUsername(string $lastName): string
    {
        $clean    = strtolower(preg_replace('/[^a-zA-Z]/', '', $lastName));
        $base     = $clean . '.SDA';
        $username = $base;
        $i = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base . $i++;
        }
        return $username;
    }

    public function index(Request $request): JsonResponse
    {
        $query = User::where('role', 'teacher');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name',      'like', "%{$search}%")
                  ->orWhere('email',    'like', "%{$search}%");
            });
        }

        $statusFilter = $request->query('status', 'active');
        if ($statusFilter === 'archived') {
            $query->whereNotNull('archived_at');
        } elseif ($statusFilter === 'active') {
            $query->whereNull('archived_at');
        }

        $sort = $request->query('sort', 'newest');
        $query->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc');

        $teachers = $query->paginate(10)->through(fn ($u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'role'           => $u->role,
            'is_first_login' => $u->is_first_login,
            'created_at'     => $u->created_at?->format('M d, Y'),
            'archived_at'    => $u->archived_at?->format('M d, Y'),
            'status'         => $u->teacherProfile?->status ?? 'Active',
            'subject'        => $u->teacherProfile?->subject ?? '—',
            'grade_level'    => $u->teacherProfile?->grade_level ?? '—',
        ]);

        return $this->success($teachers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name'  => 'required|string|max:100|regex:/^[a-zA-Z\s.\-]+$/',
            'middle_name' => 'nullable|string|max:100|regex:/^[a-zA-Z\s.\-]*$/',
            'last_name'   => 'required|string|max:100|regex:/^[a-zA-Z\s.\-]+$/',
            'suffix'      => 'nullable|string|max:20',
            'grade_level' => 'nullable|string|max:100',
            'email'       => [
                'required', 'string', 'email', 'max:255',
                'unique:teachers,email',
                'unique:users,email',
            ],
        ], [
            'first_name.required' => 'First name is required.',
            'first_name.regex'    => 'Only letters are allowed in first name.',
            'last_name.required'  => 'Last name is required.',
            'last_name.regex'     => 'Only letters are allowed in last name.',
            'middle_name.regex'   => 'Only letters are allowed in middle name.',
            'email.required'      => 'Email is required so the teacher can receive their login credentials.',
            'email.email'         => 'Please enter a valid email address.',
            'email.unique'        => 'This email address is already in use.',
        ]);

        DB::beginTransaction();
        try {
            $fullName = trim(implode(' ', array_filter([
                $data['first_name'], $data['middle_name'] ?? null,
                $data['last_name'],  $data['suffix']      ?? null,
            ])));

            $username          = $this->generateUsername($data['last_name']);
            $email             = strtolower(trim($data['email']));
            $generatedPassword = $this->generateRandomPassword();

            $user = User::create([
                'name'           => $fullName,
                'email'          => $email,
                'username'       => $username,
                'password'       => Hash::make($generatedPassword),
                'temp_password'  => $generatedPassword,
                'role'           => 'teacher',
                'is_first_login' => true,
            ]);

            Teacher::create([
                'name'          => $fullName,
                'email'         => $email,
                'subject'       => '',
                'grade_level'   => $data['grade_level'] ?? '',
                'classes_count' => 0,
                'status'        => 'Active',
            ]);

            DB::commit();

            // Send login credentials to the teacher's email.
            // Sent after commit so a mail failure never rolls back account creation —
            // the account is still considered created even if delivery fails.
            $mailResult = $this->sendCredentialsEmail($fullName, $username, $email, $generatedPassword, 'created');

            return $this->success([
                'id'                 => $user->id,
                'name'               => $fullName,
                'email'              => $email,
                'role'               => $user->role,
                'username'           => $username,
                'temporary_password' => $generatedPassword,
                'email_status'       => $mailResult['sent'] ? 'sent' : 'failed',
                'email_error'        => $mailResult['error'],
            ], $mailResult['sent']
                ? 'Teacher account created successfully. Credentials emailed to the teacher.'
                : 'Teacher account created successfully! Credentials email could not be delivered due to mail port restrictions. Temporary password: ' . $generatedPassword,
            201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to create teacher account: ' . $e->getMessage());
        }
    }

    /**
     * PATCH /api/teacher-accounts/{id}/status
     *
     * $id may be a User ID (from Accounts tab) or a Teacher profile ID
     * (from Profiles tab). We resolve both via email as the shared key.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:Active,Inactive',
        ]);

        DB::beginTransaction();
        try {
            // Try to find by User ID first (Accounts tab sends user id)
            $user = User::where('role', 'teacher')->find($id);

            if (! $user) {
                // Profiles tab sends Teacher profile ID — resolve via email
                $teacher = Teacher::findOrFail($id);
                $user    = User::where('email', $teacher->email)
                               ->where('role', 'teacher')
                               ->first();

                if (! $user) {
                    // Teacher profile exists but has no linked user account yet
                    $teacher->update(['status' => $data['status']]);
                    DB::commit();
                    return $this->success(null, 'Teacher status updated (no linked account).');
                }
            }

            // Sync status on the teacher profile record
            Teacher::where('email', $user->email)->update(['status' => $data['status']]);

            // Inactive → auto-archive the user account and revoke sessions
            $user->update([
                'archived_at' => $data['status'] === 'Inactive' ? Carbon::now() : null,
            ]);

            if ($data['status'] === 'Inactive') {
                $user->tokens()->delete();
            }

            DB::commit();
            return $this->success(null, 'Teacher status updated.');

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to update status: ' . $e->getMessage());
        }
    }

    public function restore(int $id): JsonResponse
    {
        // Resolve by User ID or Teacher profile ID
        $user = User::where('role', 'teacher')->find($id);
        if (! $user) {
            $teacher = Teacher::findOrFail($id);
            $user    = User::where('email', $teacher->email)->where('role', 'teacher')->firstOrFail();
        }

        DB::beginTransaction();
        try {
            $user->update(['archived_at' => null]);
            Teacher::where('email', $user->email)->update(['status' => 'Active']);
            DB::commit();
            return $this->success(null, 'Teacher restored to Active.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to restore: ' . $e->getMessage());
        }
    }

    public function destroy(int $id): JsonResponse
    {
        // Resolve by User ID or Teacher profile ID
        $user = User::where('role', 'teacher')->find($id);
        if (! $user) {
            $teacher = Teacher::findOrFail($id);
            $user    = User::where('email', $teacher->email)->where('role', 'teacher')->firstOrFail();
        }

        DB::beginTransaction();
        try {
            Teacher::where('email', $user->email)->delete();
            $user->tokens()->delete();
            $user->delete();
            DB::commit();
            return $this->success(null, 'Teacher account permanently removed.');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->error('Failed to delete: ' . $e->getMessage());
        }
    }

    /**
     * Sends the teacher's login credentials via email.
     * Never throws — the account/reset is always considered successful
     * either way. On failure it just logs a warning and reports back so
     * the admin UI can warn and the credentials can be shared manually.
     *
     * @return array{sent: bool, error: ?string}
     */
    private function sendCredentialsEmail(
        string $name,
        string $username,
        string $email,
        string $password,
        string $context = 'created'
    ): array {
        try {
            Mail::to($email)->send(
                new TeacherCredentialsMail($name, $username, $email, $password, $context)
            );
            return ['sent' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning("Failed to send teacher credentials email to {$email}: " . $e->getMessage());
            return ['sent' => false, 'error' => $e->getMessage()];
        }
    }

    private function generateRandomPassword(int $length = 10): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower  = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $all    = $upper . $lower . $digits;
        $chars  = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);
        return implode('', $chars);
    }
}