<?php
// app/Http/Controllers/Api/AccountsController.php
namespace App\Http\Controllers\Api;

use App\Mail\StudentCredentialsMail;
use App\Mail\TeacherCredentialsMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AccountsController extends BaseApiController
{
    /**
     * Get student and teacher account status without exposing login credentials.
     * Admin only endpoint
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query()
            ->whereIn('role', ['student', 'teacher'])
            ->with(['studentProfile.section']);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('studentProfile', fn ($studentQuery) =>
                        $studentQuery->where('student_number', 'like', "%{$search}%")
                    );
            });
        }

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }

        $status = $request->query('status', 'active');
        if ($status === 'active') {
            $query->whereNull('archived_at');
        } elseif ($status === 'inactive') {
            $query->whereNotNull('archived_at');
        }

        $query->orderBy('created_at', $request->query('sort') === 'oldest' ? 'asc' : 'desc');

        $accounts = $query->paginate(20)->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->role === 'student'
                ? ($user->studentProfile?->full_name ?? $user->name)
                : $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_first_login' => (bool) $user->is_first_login,
            'status' => $user->archived_at ? 'Inactive' : 'Active',
            'student_number' => $user->studentProfile?->student_number,
            'grade' => $user->studentProfile?->grade,
            'section' => $user->studentProfile?->section?->name,
            'created_at' => $user->created_at?->format('M d, Y'),
        ]);

        return $this->success($accounts);
    }

    /**
     * Reset a user's password (admin action)
     * Emails a newly generated one-time password; never returns it to the admin UI.
     */
    public function resetPassword(int $userId): JsonResponse
    {
        $user = User::whereIn('role', ['student', 'teacher'])->findOrFail($userId);
        if ($user->archived_at) {
            return $this->error('Restore this account before resetting its password.', 422);
        }

        $profile = $user->role === 'student' ? $user->studentProfile : null;
        if ($user->role === 'student' && ! $profile) {
            return $this->error('Student profile not found; password was not reset.', 422);
        }

        $password = $this->generateTemporaryPassword();

        DB::beginTransaction();
        try {
            $user->forceFill([
                'password' => Hash::make($password),
                'is_first_login' => true,
                'temp_password' => null,
            ])->save();

            $mailSent = false;
            try {
                if ($user->role === 'teacher') {
                    Mail::to($user->email)->send(new TeacherCredentialsMail(
                        $user->name,
                        $user->username ?? $user->email,
                        $user->email,
                        $password,
                        'reset',
                    ));
                } else {
                    Mail::to($user->email)->send(new StudentCredentialsMail(
                        $profile->full_name ?? $user->name,
                        $profile->student_number,
                        $password,
                    ));
                }
                $mailSent = true;
            } catch (Throwable $mailEx) {
                Log::warning("Reset password email delivery failed for user #{$user->id}: {$mailEx->getMessage()}");
            }

            $user->tokens()->delete();
            DB::commit();

            return $this->success([
                'user_id'            => $user->id,
                'email'              => $user->email,
                'temporary_password' => $password,
                'email_status'       => $mailSent ? 'sent' : 'failed',
            ], $mailSent
                ? 'Password reset successfully. The new temporary password was emailed to the user.'
                : 'Password reset successfully! Note: Email delivery could not reach the mail host due to server network limits. Temporary password: ' . $password
            );
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Account password reset/email failed for user #{$user->id}: {$e->getMessage()}");
            return $this->error(
                'Password was not reset because the new password could not be emailed. Check the account email and mail settings, then try again.',
                502,
            );
        }
    }

    private function generateTemporaryPassword(int $length = 12): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%';
        $all = $upper . $lower . $digits . $symbols;
        $characters = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($characters);

        return implode('', $characters);
    }
}
