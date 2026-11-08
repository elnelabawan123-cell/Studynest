<?php

namespace App\Http\Controllers\Api;

use App\Models\Activitylog;
use App\Models\Auditlog;
use App\Models\AdminSetting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends BaseApiController
{
    // ── POST /api/login ───────────────────────────────────────────────────────
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $identifier = trim($request->identifier);

        $student = Student::whereRaw('LOWER(student_number) = ?', [strtolower($identifier)])->first();
        $user = $student?->user;

        // Keep existing username/email sign-in working, especially for teachers.
        if (! $user) {
            $user = User::where('username', $identifier)
                        ->orWhere('email', $identifier)
                        ->first();
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->error('Invalid credentials. Check your Student ID or username and password.', 401);
        }

        if ($user->archived_at) {
            return $this->error('This account has been archived. Please contact the administrator.', 403);
        }

        $allowMultipleAdminLogins = $user->isAdmin() && AdminSetting::get('admin_multi_login', false);

        if (in_array($user->role, ['admin', 'teacher'], true) && $user->two_factor_enabled) {
            if (!$user->two_factor_secret) {
                return $this->error('Two-factor authentication needs to be reset. Please contact the administrator.', 403);
            }

            if (!$allowMultipleAdminLogins) {
                $user->tokens()->delete();
            }
            $challengeId = bin2hex(random_bytes(32));
            Cache::put(
                '2fa_login_challenge:' . hash('sha256', $challengeId),
                $user->id,
                now()->addMinutes(5)
            );

            return $this->success([
                'two_factor_required' => true,
                'challenge_id' => $challengeId,
            ], 'Enter your authenticator code to finish signing in.');
        }

        if (!$allowMultipleAdminLogins) {
            $user->tokens()->delete();
        }
        $token = $user->createToken('studynest_token')->plainTextToken;

        $userData = [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'username'       => $user->username,
            'role'           => $user->role,
            'is_first_login' => $user->is_first_login,
        ];

        if ($user->isStudent() && $profile = $user->studentProfile) {
            $userData['student_number'] = $profile->student_number;
            $userData['grade']          = $profile->grade;
            $userData['section']        = $profile->section?->name;
            $userData['gpa']            = $profile->gpa;
            $userData['status']         = $profile->status;
            $userData['equipped_avatar_slug']  = $profile->equipped_avatar_slug;
            $userData['equipped_avatar_emoji'] = $profile->equipped_avatar_slug
                ? \App\Models\ShopItem::where('slug', $profile->equipped_avatar_slug)->value('emoji')
                : null;
        }

        // ── Activity log ──────────────────────────────────────────────────────
        Activitylog::record(
            action:      'LOGIN',
            module:      'Auth',
            description: "{$user->name} logged in.",
            userId:      $user->id,
            userRole:    $user->role,
            ip:          $request->ip(),
        );

        return $this->success(['user' => $userData, 'token' => $token], 'Login successful.');
    }

    // ── POST /api/logout ──────────────────────────────────────────────────────
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // ── Activity log ──────────────────────────────────────────────────────
        Activitylog::record(
            action:      'LOGOUT',
            module:      'Auth',
            description: "{$user->name} logged out.",
        );

        $user->currentAccessToken()->delete();

        return $this->success(null, 'Logged out successfully.');
    }

    public function me(Request $request): JsonResponse
    {
        $user     = $request->user();
        $userData = [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'username'       => $user->username,
            'role'           => $user->role,
            'is_first_login' => $user->is_first_login,
        ];

        if ($user->isStudent() && $profile = $user->studentProfile) {
            $userData['student_number'] = $profile->student_number;
            $userData['grade']          = $profile->grade;
            $userData['section']        = $profile->section?->name;
            $userData['status']         = $profile->status;
            $userData['equipped_avatar_slug']  = $profile->equipped_avatar_slug;
            $userData['equipped_avatar_emoji'] = $profile->equipped_avatar_slug
                ? \App\Models\ShopItem::where('slug', $profile->equipped_avatar_slug)->value('emoji')
                : null;
        }

        return $this->success($userData);
    }

    public function updateMe(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|email|max:255|unique:users,email,{$user->id}",
        ]);

        $user->fill($data)->save();

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'is_first_login' => $user->is_first_login,
        ], 'Profile updated successfully.');
    }

    // ── POST /api/change-password ─────────────────────────────────────────────
    // Call this from wherever you handle password changes.
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return $this->error('Current password is incorrect.', 422);
        }

        $user->update([
            'password'       => Hash::make($request->new_password),
            'is_first_login' => false,
            'temp_password'  => null,   // ← clear so account no longer appears in export
        ]);

        // ── Activity log ──────────────────────────────────────────────────────
        Activitylog::record(
            action:      'CHANGE_PASSWORD',
            module:      'Auth',
            description: "{$user->name} changed their password.",
        );

        // ── Audit log ─────────────────────────────────────────────────────────
        Auditlog::record(
            action:      Auditlog::ACTION_PASSWORD_CHANGE,
            tableName:   'users',
            recordId:    $user->id,
            oldValue:    ['password' => '(hidden)'],
            newValue:    ['password' => '(hidden)'],
            description: "{$user->name} changed their own password.",
        );

        return $this->success(null, 'Password changed successfully.');
    }
}