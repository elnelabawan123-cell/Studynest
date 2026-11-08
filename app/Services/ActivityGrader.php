<?php

namespace App\Services;

use App\Models\QuizQuestion;

/**
 * Auto-grades Multiple Choice and Short Answer questions at submit time.
 * Photo Upload questions are never auto-graded — they're left with
 * points_awarded = null so Submission::recalculateScores() flags the
 * submission as needing manual review.
 */
class ActivityGrader
{
    /**
     * @return array{is_correct: bool|null, points_awarded: int|null}
     */
    public static function grade(QuizQuestion $question, ?string $selectedChoice, ?string $textAnswer): array
    {
        return match ($question->answer_type) {
            'Multiple Choice' => self::gradeExactMatch($question, $selectedChoice),
            'Text'             => $question->checking_mode === 'Manual'
                ? ['is_correct' => null, 'points_awarded' => null]
                : self::gradeExactMatch($question, $textAnswer),
            'Photo'            => ['is_correct' => null, 'points_awarded' => null],
            default            => ['is_correct' => null, 'points_awarded' => null],
        };
    }

    private static function gradeExactMatch(QuizQuestion $question, ?string $studentAnswer): array
    {
        $correct = self::normalize($question->correct_answer);
        $given   = self::normalize($studentAnswer);

        // No correct answer on file (shouldn't happen — StoreActivityRequest
        // requires it for Multiple Choice / Text) — treat as ungradeable.
        if ($correct === '') {
            return ['is_correct' => null, 'points_awarded' => null];
        }

        $isCorrect = $given !== '' && $given === $correct;

        return [
            'is_correct'     => $isCorrect,
            'points_awarded' => $isCorrect ? $question->points : 0,
        ];
    }

    /** Case-insensitive, whitespace-trimmed comparison so "Marcos Jr." == "marcos jr." */
    private static function normalize(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}