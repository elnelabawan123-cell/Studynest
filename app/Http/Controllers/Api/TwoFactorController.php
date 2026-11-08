<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'enabled' => (bool) $request->user()->two_factor_enabled,
        ]);
    }

    public function enable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if ($user->two_factor_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is already enabled.',
            ], 422);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password confirmation failed.',
                'errors' => ['password' => ['Incorrect password.']],
            ], 422);
        }

        $secret = Totp::secret();
        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => Crypt::encryptString($secret),
        ])->save();

        return response()->json([
            'success' => true,
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $user->email),
        ]);
    }

    public function confirmSetup(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|size:6|regex:/^\d{6}$/']);
        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json([
                'success' => false,
                'message' => 'Start two-factor setup before confirming it.',
            ], 422);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);
        if (!Totp::verify($secret, $request->code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.',
                'errors' => ['code' => ['The code you entered is incorrect.']],
            ], 422);
        }

        $user->forceFill(['two_factor_enabled' => true])->save();
        $description = "{$user->role} {$user->name} enabled two-factor authentication.";
        ActivityLog::record('toggled', 'Settings', $description);
        AuditLog::record(
            'updated',
            'users',
            $user->id,
            ['two_factor_enabled' => false],
            ['two_factor_enabled' => true],
            $description
        );

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication enabled.',
            'enabled' => true,
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string']);
        $user = $request->user();

        if (!$user->two_factor_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'Two-factor authentication is not enabled.',
            ], 422);
        }

        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password confirmation failed.',
                'errors' => ['password' => ['Incorrect password.']],
            ], 422);
        }

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ])->save();

        $description = "{$user->role} {$user->name} disabled two-factor authentication.";
        ActivityLog::record('toggled', 'Settings', $description);
        AuditLog::record(
            'updated',
            'users',
            $user->id,
            ['two_factor_enabled' => true],
            ['two_factor_enabled' => false],
            $description
        );

        return response()->json([
            'success' => true,
            'message' => 'Two-factor authentication disabled.',
            'enabled' => false,
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_id' => 'required|string|size:64|regex:/^[a-f0-9]{64}$/',
            'code' => 'required|string|size:6|regex:/^\d{6}$/',
        ]);

        $cacheKey = '2fa_login_challenge:' . hash('sha256', $request->challenge_id);
        $userId = Cache::get($cacheKey);
        $user = $userId ? User::find($userId) : null;

        if (!$user || !$user->two_factor_enabled || !$user->two_factor_secret) {
            return response()->json([
                'success' => false,
                'message' => 'This sign-in verification has expired. Please sign in again.',
            ], 422);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);
        if (!Totp::verify($secret, $request->code)) {
            ActivityLog::record(
                'failed',
                'Auth',
                "{$user->role} {$user->name} entered an invalid 2FA code.",
                $user->id,
                $user->role,
                $request->ip()
            );

            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code.',
                'errors' => ['code' => ['The code you entered is incorrect.']],
            ], 422);
        }

        Cache::forget($cacheKey);
        $token = $user->createToken('studynest_token')->plainTextToken;
        ActivityLog::record(
            'verified',
            'Auth',
            "{$user->role} {$user->name} passed 2FA verification.",
            $user->id,
            $user->role,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => 'Verification successful.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role' => $user->role,
                    'is_first_login' => $user->is_first_login,
                ],
                'token' => $token,
            ],
        ]);
    }
}
