<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class Student extends Model
{
    use HasFactory, FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['first_name', 'middle_name', 'last_name', 'suffix', 'full_name', 'grade'];
    }

    protected $fillable = [
        'user_id', 'student_number',
        'first_name', 'middle_name', 'last_name', 'suffix',
        'email', 'grade', 'section_id', 'gpa', 'status',
        'stars_balance', 'equipped_avatar_slug',
    ];

    protected $casts = [
        'gpa'        => 'float',
        'user_id'    => 'integer',
        'section_id' => 'integer',
        'stars_balance' => 'integer',
    ];

    // ── Boot ────────────────────────────────────────────────
    protected static function booted(): void
    {
        static::creating(function (Student $s) {
            if (empty($s->student_number)) {
                $s->student_number = static::generateStudentNumber();
            }
        });
    }

    // ── Accessors ────────────────────────────────────────────
    /** "Juan M. Dela Cruz Jr." */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name ? mb_substr($this->middle_name, 0, 1) . '.' : null,
            $this->last_name,
            $this->suffix,
        ]);
        return implode(' ', $parts);
    }



    // ── Helpers ──────────────────────────────────────────────
    public static function generateStudentNumber(): string
    {
        $year   = now()->year;
        $prefix = "STU-{$year}-";
        $last   = static::where('student_number', 'like', "{$prefix}%")
                         ->orderByDesc('student_number')
                         ->value('student_number');
        $seq = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generates delacruz.juan@sda.edu.ph
     * Appends a counter if the address already exists.
     */
    public static function generateEmail(string $firstName, string $lastName): string
    {
        $slug = fn(string $s) => strtolower(preg_replace('/[^a-zA-Z]/', '', $s));
        $base  = $slug($lastName) . '.' . $slug($firstName);
        $email = "{$base}@sda.edu.ph";

        $i = 1;
        while (\App\Models\User::where('email', $email)->exists()) {
            $email = "{$base}{$i}@sda.edu.ph";
            $i++;
        }
        return $email;
    }

    public function hasAccount(): bool { return ! is_null($this->user_id); }

    // Fixed default password (no longer derived from student number)
    public function defaultPassword(): string { return 'sdapass123#'; }

    // ── Relationships ─────────────────────────────────────────
    public function user()    { return $this->belongsTo(User::class); }
    public function section() { return $this->belongsTo(Section::class); }
    public function teachers(){ return $this->belongsToMany(Teacher::class, 'teacher_student'); }
    public function starTransactions() { return $this->hasMany(StarTransaction::class); }
    public function inventory() { return $this->hasMany(StudentInventory::class); }

    
    public function schoolYear()
{
    return $this->belongsTo(\App\Models\SchoolYear::class, 'school_year_id');
}

}