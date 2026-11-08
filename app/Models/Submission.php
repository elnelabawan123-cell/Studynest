<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    protected $fillable = [
        'activity_id', 'student_id', 'status',
        'auto_score', 'manual_score', 'total_score',
        'needs_manual_review',
        'started_at', 'submitted_at', 'graded_at',
        // new — added by 2026_08_02_000002 migration
        'is_late', 'current_question_index', 'completion_percentage',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'needs_manual_review' => 'boolean',
        'is_late' => 'boolean',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id', 'user_id');
    }

    public function answers()
    {
        return $this->hasMany(SubmissionAnswer::class);
    }

    /**
     * RECONSTRUCTED (I never saw your original — sanity check this).
     * Called from SubmissionController::grade() after updating
     * points_awarded/teacher_feedback/graded_at on one or more answers.
     *
    * auto_score is left untouched here (set once at submit time).
    * manual_score contains only teacher-reviewed photo answers; objective
    * answers are already included in auto_score. needs_manual_review stays
    * true as long as ANY answer still has no points_awarded.
     */
    public function recalculateScores(): void
    {
        $this->loadMissing('answers.quizQuestion');

        $stillUngraded = $this->answers->contains(fn ($a) => $a->points_awarded === null);

        // Rebuild the objective score from answer rows as well as the cached
        // column. This repairs older submissions whose auto_score was saved
        // before automatic grading completed.
        $this->auto_score = $this->answers
            ->filter(fn ($a) => $a->quizQuestion?->answer_type !== 'Photo' && $a->is_correct === true)
            ->sum(fn ($a) => $a->quizQuestion?->points ?? 0);

        $this->manual_score = $this->answers
            ->filter(fn ($a) =>
                $a->graded_at !== null &&
                ($a->quizQuestion?->answer_type === 'Photo' ||
                    $a->quizQuestion?->checking_mode === 'Manual'))
            ->sum('points_awarded');

        $this->total_score = ($this->auto_score ?? 0) + $this->manual_score;
        $this->needs_manual_review = $stillUngraded;

        if (!$stillUngraded) {
            $this->status = 'graded';
            $this->graded_at ??= now();
        }

        $this->save();
    }

    /**
     * Single source of truth for badges — Not Started | In Progress |
     * Submitted | Late Submission | Graded | Incomplete.
     */
    public function displayStatus(): string
    {
        return match ($this->status) {
            'graded' => 'Graded',
            'submitted' => $this->is_late ? 'Late Submission' : 'Submitted',
            'in_progress' => 'In Progress',
            'incomplete' => 'Incomplete',
            default => 'Not Started',
        };
    }
}
