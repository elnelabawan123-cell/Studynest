<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (lesson, student, type) — 'view' or 'download'.
 * Repeat views/downloads update `updated_at` rather than creating a
 * new row, so "Date & Time" on the teacher dashboard always reflects
 * the most recent activity, not a growing history.
 */
class LessonActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'lesson_id',
        'student_id',
        'type', // view | download
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
