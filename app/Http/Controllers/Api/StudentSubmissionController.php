<?php

namespace App\Http\Controllers\Api;

use App\Models\Activity;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Services\ActivityGrader;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * due_date affects lateness only, never availability. The only thing
 * that blocks a student is activity.status === 'Closed' (teacher-only,
 * via ActivityController::close()/toggleStatus() — see the fix needed
 * in ActivityController::computeStatus() so due_date passing no longer
 * sets status to 'Closed' on its own).
 */
class StudentSubmissionController extends BaseApiController
{
    // GET /api/student/activities/{activity}/submission
    public function show(Request $request, Activity $activity): JsonResponse
    {
        $submission = $this->existingSubmission($request, $activity);
        abort_if(!$submission, 404, 'Not started yet.');

        return $this->success($this->present($request, $submission));
    }

    // POST /api/student/activities/{activity}/start
    public function start(Request $request, Activity $activity): JsonResponse
    {
        $this->studentProfile($request);
        $this->assertOpen($activity);

        $submission = Submission::firstOrCreate(
            ['activity_id' => $activity->id, 'student_id' => $request->user()->id],
            [
                'status' => 'in_progress',
                'started_at' => now(),
                'current_question_index' => 0,
                'completion_percentage' => 0,
            ]
        );

        if (!$submission->wasRecentlyCreated && in_array($submission->status, [null, 'not_started'])) {
            $submission->status = 'in_progress';
            $submission->started_at ??= now();
            $submission->save();
        }

        return $this->success($this->present($request, $submission));
    }

    // POST /api/student/activities/{activity}/answer
    // Autosave — fired after every question. Upserts on
    // (submission_id, quiz_question_id): re-answering updates the row,
    // never creates a duplicate.
    public function saveAnswer(Request $request, Activity $activity): JsonResponse
    {
        $this->studentProfile($request);
        $submission = Submission::where('activity_id', $activity->id)
            ->where('student_id', $request->user()->id)
            ->first();
        abort_unless($submission, 422, 'Start the activity before answering.');
        abort_if(in_array($submission->status, ['submitted', 'graded']), 422, 'This has already been submitted.');
        abort_if($activity->status === 'Closed', 423, 'This task has been closed by your teacher.');
        $this->assertOpen($activity);

        $data = $request->validate([
            'quiz_question_id' => 'required|integer|exists:quiz_questions,id',
            'question_index' => 'required|integer|min:0',
            'selected_choice' => 'nullable|string',
            'text_answer' => 'nullable|string',
            'photo' => 'nullable|file|image|max:8192',
        ]);

        $existing = SubmissionAnswer::where('submission_id', $submission->id)
            ->where('quiz_question_id', $data['quiz_question_id'])
            ->first();

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('submission_answers', 'public')
            : $existing?->photo_path;

        SubmissionAnswer::updateOrCreate(
            ['submission_id' => $submission->id, 'quiz_question_id' => $data['quiz_question_id']],
            [
                'selected_choice' => $data['selected_choice'] ?? $existing?->selected_choice,
                'text_answer' => $data['text_answer'] ?? $existing?->text_answer,
                'photo_path' => $photoPath,
            ]
        );

        $totalQuestions = max($activity->quizQuestions()->count(), 1);
        $answeredCount = $submission->answers()->count();

        $submission->current_question_index = min($data['question_index'] + 1, $totalQuestions - 1);
        $submission->completion_percentage = (int) round(($answeredCount / $totalQuestions) * 100);
        if (in_array($submission->status, [null, 'not_started'])) {
            $submission->status = 'in_progress';
            $submission->started_at ??= now();
        }
        $submission->save();

        return $this->success($this->present($request, $submission));
    }

    // POST /api/student/activities/{activity}/submit
    public function submit(Request $request, Activity $activity): JsonResponse
    {
        $this->studentProfile($request);
        $submission = Submission::where('activity_id', $activity->id)
            ->where('student_id', $request->user()->id)
            ->first();
        abort_unless($submission, 422, 'Start the activity before submitting.');

        if (in_array($submission->status, ['submitted', 'graded']) && !$activity->allow_resubmission) {
            // A submit request can race with the final autosave, or be
            // retried after the server already accepted it. Return the
            // stored result so the student sees the real submitted state.
            return $this->success($this->present($request, $submission->fresh('answers')));
        }
        abort_if($activity->status === 'Closed' && !$activity->allow_resubmission, 423,
            'This task has been closed by your teacher.');
        $this->assertOpen($activity);

        $data = $request->validate([
            'answers' => 'required|array|min:1',
            'answers.*.quiz_question_id' => 'required|integer|exists:quiz_questions,id',
            'answers.*.selected_choice' => 'nullable|string',
            'answers.*.text_answer' => 'nullable|string',
            'answers.*.photo' => 'nullable|file|image|max:8192',
        ]);

        foreach ($data['answers'] as $i => $a) {
            $existing = SubmissionAnswer::where('submission_id', $submission->id)
                ->where('quiz_question_id', $a['quiz_question_id'])
                ->first();

            $photoPath = $request->hasFile("answers.$i.photo")
                ? $request->file("answers.$i.photo")->store('submission_answers', 'public')
                : $existing?->photo_path;

            SubmissionAnswer::updateOrCreate(
                ['submission_id' => $submission->id, 'quiz_question_id' => $a['quiz_question_id']],
                [
                    'selected_choice' => $a['selected_choice'] ?? $existing?->selected_choice,
                    'text_answer' => $a['text_answer'] ?? $existing?->text_answer,
                    'photo_path' => $photoPath,
                ]
            );
        }

        // Auto-grade objective answers. Photo answers stay ungraded because
        // they require the teacher's review.
        $submission->load('answers.quizQuestion');
        $autoScore = 0;
        foreach ($submission->answers as $ans) {
            $q = $ans->quizQuestion;
            if (!$q || $q->answer_type === 'Photo') {
                continue;
            }
            $grade = ActivityGrader::grade(
                $q,
                $ans->selected_choice,
                $ans->text_answer,
            );
            $ans->is_correct = $grade['is_correct'];
            $ans->points_awarded = $grade['points_awarded'];
            $ans->graded_at = now(); // auto-graded counts as graded for recalculateScores()
            $ans->save();
            $autoScore += $ans->points_awarded ?? 0;
        }

        $submittedAt = now();
        $dueAt = $this->dueAt($activity);
        $isLate = $dueAt !== null && $submittedAt->greaterThan($dueAt);

        $submission->submitted_at = $submittedAt;
        $submission->is_late = $isLate;
        $submission->auto_score = $autoScore;
        $submission->status = 'submitted';
        $submission->completion_percentage = 100;
        $submission->save();

        // Recomputes total_score/needs_manual_review and flips to
        // 'graded' automatically if there's nothing left ungraded (e.g.
        // a pure Multiple Choice quiz never needs a teacher to touch it).
        $submission->recalculateScores();

        return $this->success($this->present($request, $submission->fresh('answers')));
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Blocks start/answer/submit before an activity's open_date+open_time
     * arrives. resolveStatus() is the live source of truth (Draft/
     * Scheduled/Open/Closed) — the raw `status` column can lag behind
     * until the (currently unscheduled) sync command runs, so we must
     * compute this live rather than trust the stored column.
     */
    private function assertOpen(Activity $activity): void
    {
        $status = $activity->resolveStatus();
        abort_if($status === 'Draft', 403, 'This is not published yet.');
        abort_if($status === 'Scheduled', 423, 'This is not open yet. It opens on '
            . $activity->open_date->format('M d, Y') . ' at ' . $activity->open_time . '.');
    }

    private function studentProfile(Request $request)
    {
        $profile = $request->user()->studentProfile;
        abort_unless($profile, 403, 'No student profile found for this account.');
        return $profile;
    }

    private function existingSubmission(Request $request, Activity $activity): ?Submission
    {
        $this->studentProfile($request);
        return Submission::where('activity_id', $activity->id)
            ->where('student_id', $request->user()->id)
            ->first();
    }

    private function dueAt(Activity $activity): ?Carbon
    {
        if (!$activity->due_date) {
            return null;
        }

        // due_date is cast to a date/datetime type, so string interpolation
        // renders "2026-08-02 00:00:00" (already carrying a time) —
        // appending due_time on top created a double-time string Carbon
        // can't parse. ->format('Y-m-d') strips it back to a plain date.
        $datePart = $activity->due_date->format('Y-m-d');
        $timePart = $activity->due_time ?: '23:59:59';

        return Carbon::parse("{$datePart} {$timePart}");
    }

    private function present(Request $request, Submission $submission): array
    {
        $submission->loadMissing('answers');

        return [
            'status' => $submission->status,
            'displayStatus' => $submission->displayStatus(),
            'isLate' => (bool) $submission->is_late,
            'autoScore' => $submission->auto_score ?? 0,
            'manualScore' => $submission->manual_score ?? 0,
            'totalScore' => $submission->total_score ?? 0,
            'needsManualReview' => (bool) $submission->needs_manual_review,
            'currentQuestionIndex' => $submission->current_question_index,
            'completionPercentage' => $submission->completion_percentage,
            'answers' => $submission->answers->map(fn ($a) => [
                'quizQuestionId' => $a->quiz_question_id,
                'selectedChoice' => $a->selected_choice,
                'textAnswer' => $a->text_answer,
                'photoUrl' => $a->photo_path
                    ? rtrim($request->getSchemeAndHttpHost(), '/') . '/storage/' . ltrim($a->photo_path, '/')
                    : null,
                'isCorrect' => $a->is_correct,
                'pointsAwarded' => $a->points_awarded,
            ]),
        ];
    }
}