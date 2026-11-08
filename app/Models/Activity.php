<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class Activity extends Model
{
    use HasFactory, FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['title', 'description'];
    }

    protected $fillable = [
        'teacher_id',
        'subject_id',
        'type',                 // Quiz | Assignment | Activity
        'title',
        'description',
        'status',               // Open | Closed | Draft | Scheduled
        'open_date',
        'open_time',
        'due_date',
        'due_time',
        'total_points',
        'allow_photo_answer',
        'allow_text_answer',
        'time_limit',
        'attachment_path',
        'attachment_type',
        'is_closed',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'open_date'          => 'date:Y-m-d',
        'due_date'           => 'date:Y-m-d',
        'total_points'       => 'integer',
        'allow_photo_answer' => 'boolean',
        'allow_text_answer'  => 'boolean',
        'time_limit'         => 'integer',
        'is_closed'          => 'boolean',
        'closed_at'          => 'datetime',
    ];

    /* ─── Relations ───────────────────────────────────────────── */

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'teacher_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'closed_by');
    }

    public function quizQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class)->orderBy('order');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /* ─── Scopes ──────────────────────────────────────────────── */

    public function scopeForTeacher($query, int $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    /* ─── Helpers ─────────────────────────────────────────────── */

    /**
     * Resolve availability from the teacher-controlled status and schedule.
     * Due dates affect lateness only; they never close an activity.
     */
    public function resolveStatus(): string
    {
        if (in_array($this->status, ['Draft', 'Closed'])) {
            return $this->status;
        }

        $now  = now();
        $open = \Carbon\Carbon::parse($this->open_date->format('Y-m-d') . ' ' . $this->open_time);
        if ($open->gt($now)) return 'Scheduled';

        return 'Open';
    }

    /**
     * Adds an 'Overdue' distinction on top of resolveStatus() for display
     * only — submission is still allowed past due (see resolveStatus()'s
     * docblock), so this must never be used as a gate, only a badge.
     */
    public function getDisplayStatusAttribute(): string
    {
        $status = $this->resolveStatus();
        if ($status !== 'Open') {
            return $status;
        }

        $due = $this->due_date
            ? \Carbon\Carbon::parse($this->due_date->format('Y-m-d') . ' ' . ($this->due_time ?: '23:59:59'))
            : null;

        return ($due && $due->isPast()) ? 'Overdue' : 'Open';
    }

    /** Real count of students who have submitted (status = submitted). */
    public function getSubmissionsCountAttribute(): int
    {
        return $this->submissions()->where('status', 'submitted')->count();
    }

    /**
     * Total students enrolled in this subject.
     * Assumes Subject hasMany enrollments / belongsToMany students — adjust to your schema.
     */
    public function getTotalStudentsAttribute(): int
    {
        // If your Subject model has a `students()` relation:
        return $this->subject?->students()?->count() ?? 0;
    }
}