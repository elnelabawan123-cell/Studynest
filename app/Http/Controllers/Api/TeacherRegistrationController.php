<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activitylog;
use App\Models\AdminSetting;
use App\Models\Auditlog;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AdminAlertMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class TeacherRegistrationController extends BaseApiController
{
    public function options(): JsonResponse
    {
        return $this->success([
            'teacher_registration' => (bool) AdminSetting::get('teacher_registration', false),
            'student_self_enrollment' => (bool) AdminSetting::get('student_self_enrollment', false),
        ]);
    }

    public function register(Request $request, AdminAlertMailer $alerts): JsonResponse
    {
        if (!AdminSetting::get('teacher_registration', false)) {
            return $this->error('Teacher self-registration is currently closed.', 403);
        }

        $request->merge([
            'email' => strtolower(trim((string) $request->input('email', ''))),
        ]);

        $data = $request->validate([
            'first_name' => 'required|string|max:100|regex:/^[a-zA-Z\s.\-]+$/',
            'middle_name' => 'nullable|string|max:100|regex:/^[a-zA-Z\s.\-]*$/',
            'last_name' => 'required|string|max:100|regex:/^[a-zA-Z\s.\-]+$/',
            'suffix' => 'nullable|string|max:20',
            'grade_level' => 'nullable|string|max:100',
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('teachers', 'email'),
                Rule::unique('teacher_registration_requests', 'email')
                    ->where(fn ($query) => $query->where('status', 'pending')),
            ],
            'password' => 'required|string|min:8|confirmed',
        ]);

        $registration = DB::transaction(fn () => DB::table('teacher_registration_requests')->insertGetId([
            'first_name' => trim($data['first_name']),
            'middle_name' => isset($data['middle_name']) ? trim($data['middle_name']) : null,
            'last_name' => trim($data['last_name']),
            'suffix' => $data['suffix'] ?? null,
            'grade_level' => $data['grade_level'] ?? null,
            'email' => strtolower(trim($data['email'])),
            'password' => Hash::make($data['password']),
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $name = trim(implode(' ', array_filter([
            $data['first_name'],
            $data['middle_name'] ?? null,
            $data['last_name'],
            $data['suffix'] ?? null,
        ])));
        $mail = $alerts->send(
            'New teacher registration request',
            "A new teacher registration is waiting for approval.\n\nName: {$name}\nEmail: {$data['email']}\n"
            . 'Review the request in StudyNest Admin > Teachers > Registrations.'
        );

        Activitylog::record('created', 'Teacher Registration', "Teacher {$name} submitted a registration request.");

        return $this->success([
            'request_id' => $registration,
            'email_status' => $mail['status'],
        ], 'Registration request submitted. An administrator must approve it before you can sign in.', 201);
    }

    public function index(): JsonResponse
    {
        $requests = DB::table('teacher_registration_requests')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->get([
                'id', 'first_name', 'middle_name', 'last_name', 'suffix',
                'grade_level', 'email', 'created_at',
            ]);

        return $this->success($requests);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $teacher = DB::transaction(function () use ($request, $id): array {
            $registration = DB::table('teacher_registration_requests')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$registration || $registration->status !== 'pending') {
                abort(404, 'Pending teacher registration not found.');
            }

            $name = trim(implode(' ', array_filter([
                $registration->first_name,
                $registration->middle_name,
                $registration->last_name,
                $registration->suffix,
            ])));
            $base = strtolower(preg_replace('/[^a-zA-Z]/', '', $registration->last_name)) . '.SDA';
            $username = $base;
            for ($number = 1; User::where('username', $username)->exists(); $number++) {
                $username = $base . $number;
            }

            $user = User::create([
                'name' => $name,
                'email' => $registration->email,
                'username' => $username,
                'password' => $registration->password,
                'role' => 'teacher',
                'is_first_login' => false,
            ]);

            Teacher::create([
                'name' => $name,
                'email' => $registration->email,
                'subject' => '',
                'grade_level' => $registration->grade_level ?? '',
                'classes_count' => 0,
                'status' => 'Active',
            ]);

            DB::table('teacher_registration_requests')
                ->where('id', $registration->id)
                ->update([
                    'status' => 'approved',
                    'password' => null,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            return ['user' => $user, 'name' => $name];
        });

        Activitylog::record('approved', 'Teacher Registration', "Admin approved teacher registration for {$teacher['name']}.");
        Auditlog::record(
            'created',
            'users',
            $teacher['user']->id,
            null,
            ['name' => $teacher['name'], 'email' => $teacher['user']->email, 'role' => 'teacher'],
            "Admin approved teacher registration for {$teacher['name']}."
        );

        return $this->success([
            'id' => $teacher['user']->id,
            'name' => $teacher['name'],
            'email' => $teacher['user']->email,
            'username' => $teacher['user']->username,
        ], 'Teacher registration approved.');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $updated = DB::table('teacher_registration_requests')
            ->where('id', $id)
            ->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'password' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

        if (!$updated) {
            return $this->error('Pending teacher registration not found.', 404);
        }

        Activitylog::record('rejected', 'Teacher Registration', "Admin rejected teacher registration #{$id}.");
        Auditlog::record(
            'updated',
            'teacher_registration_requests',
            $id,
            ['status' => 'pending'],
            ['status' => 'rejected'],
            "Admin rejected teacher registration #{$id}."
        );

        return $this->success(null, 'Teacher registration rejected.');
    }
}
