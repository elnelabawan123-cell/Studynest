<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PendingCredentialsController extends BaseApiController
{
    /**
     * GET /api/admin/pending-credentials
     *
     * Returns all active teachers and students who have NOT completed
     * their first login (is_first_login = true), along with their
     * temp_password for the batch export PDF.
     *
     * temp_password is:
     *  - Set when an account is created or password is reset
     *  - Cleared (set to NULL) when the user completes first login
     *    (handle this in your AuthController / change-password flow)
     */
    public function index(): JsonResponse
    {
        // ── Teachers ─────────────────────────────────────────────
        $teachers = User::where('role', 'teacher')
            ->where('is_first_login', true)
            ->whereNull('archived_at')
            ->with('teacherProfile')
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
                // Backfill: if teacher was created before temp_password existed,
                // generate a new one, persist it, and return it.
                if (! $u->temp_password) {
                    $plain = Str::password(10, true, true, false);
                    $u->update([
                        'temp_password' => $plain,
                        'password'      => Hash::make($plain),
                    ]);
                    $u->temp_password = $plain;
                }
                return [
                'id'             => $u->id,
                'name'           => $u->name,
                'username'       => $u->username,
                'email'          => $u->email,
                'temp_password'  => $u->temp_password,
                'grade_level'    => $u->teacherProfile?->grade_level ?? '—',
                'subject'        => $u->teacherProfile?->subject     ?? '—',
                'created_at'     => $u->created_at?->format('M d, Y'),
                ];
            });

        // ── Students ─────────────────────────────────────────────
        $students = User::where('role', 'student')
            ->where('is_first_login', true)
            ->whereNull('archived_at')
            ->with(['studentProfile.section', 'studentProfile.schoolYear'])
            ->orderBy('name')
            ->get()
            ->map(fn ($u) => [
                'id'             => $u->id,
                'name'           => $u->studentProfile?->full_name ?? $u->name,
                'username'       => $u->username,
                'temp_password'  => 'sdapass123#',
                'student_number' => $u->studentProfile?->student_number ?? '—',
                'grade'          => $u->studentProfile?->grade          ?? '—',
                'section'        => $u->studentProfile?->section?->name ?? '—',
                'school_year'    => $u->studentProfile?->schoolYear?->label ?? '—',
                'created_at'     => $u->created_at?->format('M d, Y'),
            ]);

        return $this->success([
            'teachers'      => $teachers,
            'students'      => $students,
            'teacher_count' => $teachers->count(),
            'student_count' => $students->count(),
            'generated_at'  => now()->format('F d, Y h:i A'),
        ]);
    }
}