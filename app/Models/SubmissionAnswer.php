<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubmissionAnswer extends Model
{
    // Matches your actual `submission_answers` columns (confirmed via
    // Schema::getColumnListing) — teacher_feedback and graded_at are
    // real columns; the model I sent you earlier dropped both, which is
    // why SubmissionController::grade() would have broken.
    protected $fillable = [
        'submission_id', 'quiz_question_id',
        'selected_choice', 'text_answer', 'photo_path',
        'is_correct', 'points_awarded',
        'teacher_feedback', 'graded_at',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
        'graded_at' => 'datetime',
    ];

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    // Your real SubmissionController/SubmissionDetailResource call this
    // as quizQuestion(), not question() — restored to match.
    public function quizQuestion()
    {
        return $this->belongsTo(QuizQuestion::class, 'quiz_question_id');
    }
}
