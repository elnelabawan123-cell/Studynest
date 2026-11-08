<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full submission detail for the teacher grading screen.
 * Shape: { ...same as SubmissionResource, activityTitle, totalPoints, answers: [...] }
 */
class SubmissionDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'studentId'          => $this->student?->id ?? $this->student_id,
            'studentName'        => $this->student?->full_name ?? 'Unknown student',
            'activityId'         => $this->activity_id,
            'activityTitle'      => $this->activity?->title ?? '',
            'totalPoints'        => (int) ($this->activity?->total_points ?? 0),
            'status'             => $this->status,
            'autoScore'          => (int) $this->auto_score,
            'manualScore'        => (int) $this->manual_score,
            'totalScore'         => (int) $this->total_score,
            'needsManualReview'  => (bool) $this->needs_manual_review,
            'startedAt'          => optional($this->started_at)->toISOString(),
            'submittedAt'        => optional($this->submitted_at)->toISOString(),
            'gradedAt'           => optional($this->graded_at)->toISOString(),

            'answers' => $this->whenLoaded('answers', fn () => $this->answers->map(fn ($a) => [
                'id'              => $a->id,
                'quizQuestionId'  => $a->quiz_question_id,
                'question'        => $a->quizQuestion?->question ?? '',
                'answerType'      => $a->quizQuestion?->answer_type ?? '',
                'checkingMode'    => $a->quizQuestion?->checking_mode ?? 'Auto',
                'points'          => (int) ($a->quizQuestion?->points ?? 0),
                'correctAnswer'   => $a->quizQuestion?->correct_answer,

                'selectedChoice'  => $a->selected_choice,
                'textAnswer'      => $a->text_answer,
                'photoUrl'        => $a->photo_path
                    ? 'teacher/submissions/' . $this->id . '/answers/' . $a->id . '/photo'
                    : null,
                'photoIsPdf'      => $a->photo_path
                    ? strtolower(pathinfo($a->photo_path, PATHINFO_EXTENSION)) === 'pdf'
                    : false,

                'isCorrect'       => $a->is_correct,
                'pointsAwarded'   => $a->points_awarded,
                'teacherFeedback' => $a->teacher_feedback,
                'gradedAt'        => optional($a->graded_at)->toISOString(),
                'needsGrading'    => $a->quizQuestion?->checking_mode === 'Manual' && $a->points_awarded === null,
            ])->values()),
        ];
    }
}