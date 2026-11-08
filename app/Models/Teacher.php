<?php
// app/Models/Teacher.php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class Teacher extends Model
{
    use HasFactory, FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['name', 'subject', 'grade_level'];
    }

    protected $fillable = [
        'name',
        'email',
        'phone',
        'subject',
        'grade_level',
        'classes_count',
        'status',
    ];

    protected $casts = [
        'classes_count' => 'integer',
    ];

    // ──────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────

    /**
     * Students assigned to this teacher (many-to-many via teacher_student pivot).
     */
    public function students()
    {
        return $this->belongsToMany(Student::class, 'teacher_student');
    }

    
    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'subject_teacher')
                    ->withTimestamps();
    }

    /**
     * The User login account linked to this teacher profile.
     * ← NEW (needed for mySubjects() to look up teacher by email)
     */
    public function user()
    {
        return $this->hasOne(User::class, 'email', 'email')
                    ->where('role', 'teacher');
    }

    
}