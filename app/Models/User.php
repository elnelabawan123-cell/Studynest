<?php
// app/Models/User.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['name'];
    }

    protected $fillable = [
        'name',
        'email',
        'username',             // ← NEW: auto-generated lastname.SDA
        'password',
        'role',
        'is_first_login',       // ← NEW: forces password change on first login
        'archived_at',          // ← NEW: set when status → Inactive
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'temp_password',
    ];

    protected $casts = [
        'email_verified_at'   => 'datetime',
        'password'            => 'hashed',
        'must_change_password'=> 'boolean',
        'is_first_login'      => 'boolean',   // ← NEW
        'archived_at'         => 'datetime',  // ← NEW
        'two_factor_enabled'  => 'boolean',
    ];

    // ──────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────

    public function teacherProfile()
    {
        return $this->hasOne(Teacher::class, 'email', 'email');
    }

    public function studentProfile()
    {
        return $this->hasOne(Student::class, 'user_id', 'id');
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    public function isAdmin():   bool { return $this->role === 'admin'; }
    public function isTeacher(): bool { return $this->role === 'teacher'; }
    public function isStudent(): bool { return $this->role === 'student'; }

    /** Generates an unambiguous random temporary password for initial sign-in. */
    public static function generateTemporaryPassword(int $length = 10): string
    {
        $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower  = 'abcdefghjkmnpqrstuvwxyz';
        $digits = '23456789';
        $all    = $upper . $lower . $digits;

        $chars = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
        ];
        for ($i = count($chars); $i < $length; $i++) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}