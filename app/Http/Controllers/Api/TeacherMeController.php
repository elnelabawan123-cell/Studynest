<?php

namespace App\Http\Controllers\Api;
use App\Models\Teacher;
use App\Models\Activitylog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
 
class TeacherMeController extends BaseApiController
{
    /**
     * GET /api/teacher/me
     * Returns authenticated teacher's profile + assigned subjects.
     */
    public function show(Request $request): JsonResponse
    {
        $user    = $request->user();
        $profile = Teacher::where('email', $user->email)->with('subjects')->first();
 
        return $this->success([
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $profile?->phone,
            'role'           => $user->role,
            'is_first_login' => $user->is_first_login,
            'subjects'       => $profile?->subjects->map(fn ($s) => [
                'id'          => $s->id,
                'code'        => $s->code,
                'name'        => $s->name,
                'grade_level' => $s->grade_level,
            ]) ?? [],
        ]);
    }
 
    /**
     * PUT /api/teacher/me
     * Teacher updates their own name, email, or phone.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
 
        $oldEmail = $user->email;
        $data = $request->validate([
            'name'  => 'sometimes|string|max:255',
            'email' => "sometimes|email|unique:users,email,{$user->id}",
            'phone' => 'sometimes|nullable|string|max:30',
        ]);
 
        if (!empty($data['name']))  $user->name  = $data['name'];
        if (!empty($data['email'])) $user->email = $data['email'];
        $user->save();
 
        // Sync name/email to teacher profile
        $profile = Teacher::where('email', $oldEmail)->first();
        if ($profile) {
            $profile->update(array_filter([
                'name'  => $data['name']  ?? null,
                'email' => $data['email'] ?? null,
                'phone' => array_key_exists('phone', $data) ? $data['phone'] : null,
            ], fn ($value) => $value !== null));
        }
 
        return $this->success([
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'phone' => $profile?->phone,
        ], 'Profile updated successfully.');
    }
 
    /**
     * POST /api/teacher/change-password
     * Authenticated teacher changes their own password.
     * Clears is_first_login on success.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password'          => 'required|string',
            'password'                  => 'required|string|min:8|confirmed',
        ]);
 
        $user = $request->user();
 
        if (! Hash::check($request->current_password, $user->password)) {
            return $this->error('Current password is incorrect.', 422);
        }
 
        if ($request->password === $request->current_password) {
            return $this->error('New password must be different from your current password.', 422);
        }
 
        $user->update([
            'password'       => Hash::make($request->password),
            'is_first_login' => false,
        ]);
 
        // Keep current session; revoke all other tokens
        $currentId = $request->user()->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentId)->delete();
 
        return $this->success(
            ['is_first_login' => false],
            'Password changed successfully. Welcome to StudyNest!'
        );
    }

    public function sessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()?->getKey();
        $sessions = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get(['id', 'name', 'created_at', 'last_used_at'])
            ->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'current' => (string) $token->id === (string) $currentTokenId,
            ]);

        return $this->success($sessions);
    }

    public function endSession(Request $request, int $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($tokenId)->firstOrFail();
        $isCurrent = (string) $token->getKey() === (string) $request->user()->currentAccessToken()?->getKey();
        $token->delete();

        return $this->success(['current' => $isCurrent], 'Session signed out.');
    }

    public function endOtherSessions(Request $request): JsonResponse
    {
        $currentTokenId = $request->user()->currentAccessToken()?->getKey();
        $request->user()->tokens()->where('id', '!=', $currentTokenId)->delete();

        return $this->success(null, 'All other sessions have been signed out.');
    }

    public function loginHistory(Request $request): JsonResponse
    {
        $history = Activitylog::query()
            ->where('user_id', $request->user()->id)
            ->where('module', 'Auth')
            ->whereIn('action', ['LOGIN', 'verified', 'failed'])
            ->latest()
            ->limit(30)
            ->get(['id', 'action', 'description', 'ip_address', 'created_at'])
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'action' => $entry->action,
                'description' => $entry->description,
                'ip_address' => $entry->ip_address,
                'created_at' => $entry->created_at,
            ]);

        return $this->success($history);
    }
}
 
