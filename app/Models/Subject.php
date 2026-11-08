<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class Subject extends Model
{
    use FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['name', 'grade_level'];
    }

    protected $fillable = [
        'code', 'name', 'grade_level', 'units', 'status',
    ];

    protected $appends = ['teachers_count'];

    /** Subject ↔ Teacher (many-to-many) */
    public function teachers(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Teacher::class, 'subject_teacher')
                    ->withTimestamps();
    }

    /** Subject ↔ Student (many-to-many) */
    public function students(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'subject_student')
                    ->withTimestamps();
    }

    public function getTeachersCountAttribute(): int
    {
        return $this->teachers()->count();
    }
}