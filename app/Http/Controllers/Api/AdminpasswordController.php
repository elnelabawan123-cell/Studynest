<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminPasswordController extends Controller
{
    // ── POST /api/admin/change-password ──────────────────────────────────────
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed', // requires new_password_confirmation
        ]);

        $user = $request->user();

        // 1. Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
                'errors'  => ['current_password' => ['Current password is incorrect.']],
            ], 422);
        }

        // 2. Prevent reusing the same password
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'New password must be different from the current password.',
                'errors'  => ['new_password' => ['New password must be different from your current password.']],
            ], 422);
        }

        // 3. Update with bcrypt hash
        $user->update(['password' => Hash::make($request->new_password)]);

        // 4. Logs
        ActivityLog::record('updated', 'Settings', 'Admin changed their password.');

        AuditLog::record(
            'updated', 'users', $user->id,
            ['password' => '(hidden)'],
            ['password' => '(hashed & updated)'],
            "Admin {$user->name} changed their password."
        );

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }
}