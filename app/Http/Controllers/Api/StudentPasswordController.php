<?php
namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StudentPasswordController extends BaseApiController
{
    /**
     * POST /api/student/change-password
     * Called by Flutter on first login (is_first_login === true).
     */
    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! $user->isStudent()) {
            return $this->error('This endpoint is for students only.', 403);
        }

        if (! Hash::check($request->current_password, $user->password)) {
            return $this->error('Current password is incorrect.', 422);
        }

        // Prevent reusing the default password
        if ($request->password === 'sdapass123#') {
            return $this->error('You cannot keep the default password. Please choose a new one.', 422);
        }

        $user->update([
            'password'       => Hash::make($request->password),
            'is_first_login' => false,
        ]);

        // Stay logged in on this device; revoke others
        $currentId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentId)->delete();

        return $this->success(
            ['is_first_login' => false],
            'Password changed successfully. Welcome to StudyNest!'
        );
    }
}