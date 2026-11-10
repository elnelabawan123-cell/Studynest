<?php

namespace App\Http\Controllers\Api;

use App\Models\StarTransaction;
use App\Models\Student;
use App\Models\StudentGameReward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentGameRewardController extends BaseApiController
{
    /**
     * Grade 1 Abakada habitats, in curriculum order — mirrors the letter
     * counts baked into Flutter's app_state_g1.dart (`kAbakadaOrderG1`).
     * Only Kagubatan ng Unang Titik (5 letters) is offered for now; the
     * other habitats are intentionally not part of the curriculum yet.
     * Kept here so the backend can validate "is the previous letter
     * actually done?" without trusting the client.
     */
    private const YUNIT_ARALIN_COUNTS = [5];

    /** Every letter offers exactly 4 assigned mechanics (see AralinG1.assignedGames). */
    private const REQUIRED_MECHANICS_PER_LETTER = 4;

    public function index(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        return $this->success(
            StudentGameReward::where('student_id', $student->id)
                ->where('stars', '>', 0)
                ->latest()
                ->get()
        );
    }

    public function progressIndex(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        $rows = StudentGameReward::where('student_id', $student->id)
            ->whereNotNull('progress_data')
            ->get(['game_key', 'stars', 'status', 'progress_data', 'updated_at']);

        return $this->success($rows);
    }

    public function saveProgress(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        $data = $request->validate([
            'game_key' => 'required|string|max:100',
            'title' => 'nullable|string|max:255',
            'grade' => 'required|integer|min:1|max:3',
            'yunit' => 'required|integer|min:0|max:10',
            'aralin' => 'required|integer|min:0|max:20',
            'has_rounds' => 'required|boolean',
            'completed_rounds' => 'required|integer|min:0|max:50',
            'current_round' => 'required|integer|min:1|max:50',
            'total_rounds' => 'required|integer|min:1|max:50',
            'round_scores' => 'nullable|array',
            'round_scores.*' => 'integer|min:0',
            'total_score' => 'required|integer|min:0',
            'earned_stars' => 'required|integer|min:0|max:3',
            'correct_answers' => 'required|integer|min:0',
            'incorrect_answers' => 'required|integer|min:0',
            'game_completed' => 'required|boolean',
        ]);

        $record = DB::transaction(function () use ($student, $data) {
            $lockedStudent = Student::where('id', $student->id)->lockForUpdate()->first();
            $reward = StudentGameReward::firstOrNew([
                'student_id' => $lockedStudent->id,
                'game_key' => $data['game_key'],
            ]);

            $prevProgress = is_array($reward->progress_data) ? $reward->progress_data : [];
            $wasCompleted = ($reward->exists && $reward->status === 'completed') || (bool) ($prevProgress['game_completed'] ?? false);
            $prevCompletedRounds = (int) ($prevProgress['completed_rounds'] ?? 0);
            $prevBestStars = max((int) ($reward->stars ?? 0), (int) ($prevProgress['earned_stars'] ?? 0));

            $mergedCompletedRounds = max($prevCompletedRounds, (int) $data['completed_rounds']);
            $mergedGameCompleted = $wasCompleted || (bool) $data['game_completed'];
            $mergedBestStars = max($prevBestStars, (int) $data['earned_stars']);

            $reward->title = $reward->title ?: ($data['title'] ?? '3D Mini-Game');
            $reward->subject = $reward->subject ?: ('Grade ' . (int) $data['grade']);
            $reward->emoji = $reward->emoji ?: '⭐';
            if (! $reward->exists) {
                $reward->stars = 0;
                $reward->attempts = 0;
            }
            $reward->status = $mergedGameCompleted ? 'completed' : ($reward->status ?: 'in_progress');

            $reward->progress_data = [
                'student_id' => $lockedStudent->student_number ?: (string) $lockedStudent->id,
                'game_id' => $data['game_key'],
                'grade' => (int) $data['grade'],
                'yunit' => (int) $data['yunit'],
                'aralin' => (int) $data['aralin'],
                'has_rounds' => (bool) $data['has_rounds'],
                'total_rounds' => (int) $data['total_rounds'],
                'completed_rounds' => $mergedCompletedRounds,
                'current_round' => $mergedGameCompleted
                    ? (int) $data['total_rounds']
                    : max((int) $data['current_round'], min((int) $data['total_rounds'], $mergedCompletedRounds + 1)),
                'round_scores' => array_values($data['round_scores'] ?? ($prevProgress['round_scores'] ?? [])),
                'total_score' => max((int) ($prevProgress['total_score'] ?? 0), (int) $data['total_score']),
                'earned_stars' => $mergedBestStars,
                'correct_answers' => max((int) ($prevProgress['correct_answers'] ?? 0), (int) $data['correct_answers']),
                'incorrect_answers' => max((int) ($prevProgress['incorrect_answers'] ?? 0), (int) $data['incorrect_answers']),
                'game_completed' => $mergedGameCompleted,
                'updated_at' => now()->toIso8601String(),
            ];
            $reward->save();

            return $reward->fresh();
        });

        return $this->success($record);
    }

    public function store(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        $data = $request->validate([
            'game_key' => 'required|string|max:100',
            'title' => 'required|string|max:255',
            'subject' => 'nullable|string|max:255',
            'emoji' => 'nullable|string|max:20',
            'stars' => 'required|integer|min:1|max:3',
        ]);

        $prerequisiteError = $this->checkPrerequisite($student->id, $data['game_key']);
        if ($prerequisiteError !== null) {
            return $this->error($prerequisiteError, 403);
        }

        $reward = DB::transaction(function () use ($student, $data) {
            // Lock the student row for the duration of this transaction so
            // two rapid/concurrent completions of the same game can't both
            // read the same "before" balance and both credit stars.
            $lockedStudent = Student::where('id', $student->id)->lockForUpdate()->first();

            $reward = StudentGameReward::firstOrNew([
                'student_id' => $lockedStudent->id,
                'game_key' => $data['game_key'],
            ]);

            $isFirstCompletion = !$reward->exists;
            $previousStars = $isFirstCompletion ? 0 : $reward->stars;

            if ($isFirstCompletion || $data['stars'] > $reward->stars) {
                $reward->fill([
                    'title' => $data['title'],
                    'subject' => $data['subject'] ?? 'Grade 1',
                    'emoji' => $data['emoji'] ?? '⭐',
                    'stars' => $data['stars'],
                    'status' => 'completed',
                ]);
            }
            $reward->completed_at = $reward->completed_at ?? now();
            $reward->attempts = ($reward->attempts ?? 0) + 1;
            $reward->save();

            // Credit only the IMPROVEMENT over this game_key's previous best
            // star count — a first play with 1 star credits 1; retaking it
            // and reaching 3 stars later credits 2 more. A replay that ties
            // or does worse than the existing best credits nothing, so a
            // student can't farm stars by replaying the same lesson, but
            // improving on a rough first attempt is never a dead end.
            $starsToCredit = max(0, $data['stars'] - $previousStars);
            if ($starsToCredit > 0) {
                $lockedStudent->stars_balance += $starsToCredit;
                $lockedStudent->save();

                StarTransaction::create([
                    'student_id' => $lockedStudent->id,
                    'amount' => $starsToCredit,
                    'balance_after' => $lockedStudent->stars_balance,
                    'type' => 'game_reward',
                    'reference' => $data['game_key'],
                    'description' => 'Completed ' . $data['title'],
                ]);
            }

            return $reward;
        });

        return $this->success($reward->fresh());
    }

    /**
     * Returns a human-readable error message if `game_key`'s prerequisite
     * isn't satisfied yet, or null if it's fine to proceed. Deliberately
     * scoped to aralin/lesson-level gating (not per-mechanic), matching
     * what the Flutter unlock logic already operates on.
     */
    private function checkPrerequisite(int $studentId, string $gameKey): ?string
    {
        $parts = explode(':', $gameKey);

        if ($parts[0] === 'g1' && count($parts) >= 3) {
            $yunitIdx = (int) $parts[1];
            $aralinIdx = (int) $parts[2];

            if ($aralinIdx === 0 && $yunitIdx === 0) {
                return null; // very first letter — always open
            }

            [$prevYunitIdx, $prevAralinIdx] = $aralinIdx > 0
                ? [$yunitIdx, $aralinIdx - 1]
                : [$yunitIdx - 1, (self::YUNIT_ARALIN_COUNTS[$yunitIdx - 1] ?? 1) - 1];

            $completedCount = StudentGameReward::where('student_id', $studentId)
                ->where('game_key', 'like', "g1:{$prevYunitIdx}:{$prevAralinIdx}:%")
                ->where('stars', '>', 0)
                ->distinct()
                ->count('game_key');

            if ($completedCount < self::REQUIRED_MECHANICS_PER_LETTER) {
                return 'Kailangan mo munang tapusin ang nakaraang titik.';
            }
            return null;
        }

        if ($parts[0] === 'g1strand' && count($parts) >= 3) {
            $unitIdx = (int) $parts[1];
            $lessonIdx = (int) $parts[2];

            if ($lessonIdx === 0) {
                return null; // each strand unit's first lesson is independently open
            }

            $prevCompleted = StudentGameReward::where('student_id', $studentId)
                ->where('game_key', "g1strand:{$unitIdx}:" . ($lessonIdx - 1))
                ->where('stars', '>', 0)
                ->exists();

            if (!$prevCompleted) {
                return 'Kailangan mo munang tapusin ang nakaraang aralin.';
            }
            return null;
        }

        // Unrecognized game_key shape (e.g. other grades' free-form keys) —
        // no curriculum-order rule to enforce, so allow it through.
        return null;
    }
}
