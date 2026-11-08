<?php

namespace App\Http\Controllers\Api;

use App\Models\Activity;
use App\Models\QuizQuestion;
use App\Models\ShopItem;
use App\Models\Submission;
use App\Models\StarTransaction;
use App\Models\Student;
use App\Models\StudentInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentInventoryController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        return $this->success(
            StudentInventory::with('shopItem')
                ->where('student_id', $student->id)
                ->where('quantity', '>', 0)
                ->get()
        );
    }

    public function consume(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;
        abort_unless($student, 403, 'No student profile found for this account.');

        $data = $request->validate([
            'item_slug' => 'required|string|exists:shop_items,slug',
            'activity_id' => 'nullable|integer|exists:activities,id',
            'quiz_question_id' => 'nullable|integer|exists:quiz_questions,id',
            'context_label' => 'nullable|string|max:255',
        ]);

        $item = ShopItem::where('slug', $data['item_slug'])->first();

        $effectType = ($item->effect_config ?? [])['type'] ?? null;
        if (!in_array($effectType, ['hint', 'extra_time'], true)) {
            return $this->error('This item cannot be used during a quiz.', 422);
        }
        if (empty($data['activity_id'])) {
            return $this->error('An active quiz is required to use this item.', 422);
        }
        if ($effectType === 'hint' && empty($data['quiz_question_id'])) {
            return $this->error('Choose a question before using the answer reveal.', 422);
        }

        try {
            $result = DB::transaction(function () use ($student, $item, $data, $request, $effectType) {
                // Lock the student row too — this is what makes a rapid
                // double-tap unable to consume the same last unit twice:
                // the second transaction blocks until the first commits,
                // then sees quantity already at 0.
                $lockedStudent = Student::where('id', $student->id)->lockForUpdate()->first();

                $activity = Activity::query()
                    ->whereKey($data['activity_id'])
                    ->where('type', 'Quiz')
                    ->first();
                if (!$activity || $activity->resolveStatus() !== 'Open') {
                    throw new \RuntimeException('QUIZ_UNAVAILABLE');
                }
                if (!$activity->subject()
                    ->whereHas('students', fn ($query) => $query->whereKey($lockedStudent->id))
                    ->exists()) {
                    throw new \RuntimeException('QUIZ_ACCESS_DENIED');
                }

                $submission = Submission::query()
                    ->where('activity_id', $activity->id)
                    ->where('student_id', $request->user()->id)
                    ->lockForUpdate()
                    ->first();
                if (!$submission || $submission->status !== 'in_progress') {
                    throw new \RuntimeException('QUIZ_NOT_IN_PROGRESS');
                }

                $question = null;
                if ($effectType === 'hint') {
                    $question = QuizQuestion::query()
                        ->whereKey($data['quiz_question_id'])
                        ->where('activity_id', $activity->id)
                        ->where('answer_type', '!=', 'Photo')
                        ->whereNotNull('correct_answer')
                        ->first();
                    if (!$question || trim($question->correct_answer) === '') {
                        throw new \RuntimeException('NO_ANSWER_TO_REVEAL');
                    }
                }

                $inventory = StudentInventory::where('student_id', $lockedStudent->id)
                    ->where('shop_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory || $inventory->quantity < 1) {
                    throw new \RuntimeException('NOT_OWNED');
                }

                $inventory->quantity -= 1;
                $inventory->save();

                $effect = $item->effect_config ?? [];
                if ($effectType === 'hint') {
                    $effect['correct_answer'] = $question->correct_answer;
                    $effect['reveal_seconds'] = 5;
                }
                if ($effectType === 'extra_time') {
                    $effect['seconds'] = 60;
                }

                // Record the usage in the same ledger as earn/spend — a
                // zero-amount row, so Reward History can show "Used 1 Quiz
                // Hint — Quiz: Letter B" without touching the star balance.
                StarTransaction::create([
                    'student_id' => $lockedStudent->id,
                    'amount' => 0,
                    'balance_after' => $lockedStudent->stars_balance,
                    'type' => 'usage',
                    'reference' => $item->slug,
                    'description' => 'Used 1 ' . $item->name .
                        (!empty($data['context_label']) ? ' — ' . $data['context_label'] : ''),
                ]);

                return [
                    'remaining_quantity' => $inventory->quantity,
                    'effect_config' => $effect,
                ];
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'NOT_OWNED') {
                return $this->error("You don't own this item.", 422);
            }
            if ($e->getMessage() === 'QUIZ_UNAVAILABLE') {
                return $this->error('This quiz is not currently open.', 422);
            }
            if ($e->getMessage() === 'QUIZ_NOT_IN_PROGRESS') {
                return $this->error('Start the quiz before using an item.', 422);
            }
            if ($e->getMessage() === 'QUIZ_ACCESS_DENIED') {
                return $this->error('You are not enrolled in this quiz subject.', 403);
            }
            if ($e->getMessage() === 'NO_ANSWER_TO_REVEAL') {
                return $this->error('There is no answer available to reveal for this question.', 422);
            }
            throw $e;
        }

        // This never touches stars — the cost was already paid at
        // purchase time in StudentShopController::purchase().
        return $this->success($result, 'Item used.');
    }

}
