<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GradeSubmissionRequest;
use App\Http\Resources\SubmissionDetailResource;
use App\Models\Activity;
use App\Models\Activitylog;
use App\Models\Auditlog;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    // ── GET /api/teacher/activities/{activity}/submissions ───────────────────
    // Full roster: every enrolled student, each tagged with their real
    // status — including "not_started" for students with no submission row
    // at all (that status only exists here, never persisted in the DB).
    public function index(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeTeacher($request, $activity);

        $submissions = $activity->submissions()
            ->with(['student', 'answers.quizQuestion'])
            ->get()
            ->each(fn (Submission $submission) => $submission->recalculateScores())
            ->keyBy('student_id');
        $enrolled    = $activity->subject?->students()->get() ?? collect();

        $roster = $enrolled->map(function ($student) use ($submissions) {
            $s = $student->user_id === null ? null : $submissions->get($student->user_id);
            return [
                'studentId'            => $student->id,
                'studentName'          => $student->full_name,
                'status'               => $s?->status ?? 'not_started',
                'displayStatus'        => $s?->displayStatus() ?? 'Not Started',
                'isLate'               => (bool) ($s?->is_late ?? false),
                'autoScore'            => $s?->auto_score ?? 0,
                'manualScore'          => $s?->manual_score ?? 0,
                'totalScore'           => $s?->total_score ?? 0,
                'completionPercentage' => $s?->completion_percentage ?? 0,
                'needsManualReview'    => (bool) ($s?->needs_manual_review ?? false),
                'submittedAt'          => optional($s?->submitted_at)->toISOString(),
                'submissionId'         => $s?->id,
            ];
        })->values();

        return response()->json([
            'data' => $roster,
            'meta' => [
                'totalStudents'    => $enrolled->count(),
                'notStartedCount'  => $enrolled->count() - $submissions->count(),
                'submittedCount'   => $submissions->where('status', 'submitted')->where('is_late', false)->count(),
                'lateCount'        => $submissions->where('is_late', true)->count(),
                'inProgressCount'  => $submissions->where('status', 'in_progress')->count(),
                'gradedCount'      => $submissions->where('status', 'graded')->count(),
                'incompleteCount'  => $submissions->where('status', 'incomplete')->count(),
                'needsReviewCount' => $submissions->where('needs_manual_review', true)->count(),
            ],
        ]);
    }

    // ── GET /api/teacher/submissions/{submission} ─────────────────────────────
    public function show(Request $request, Submission $submission): JsonResponse
    {
        $this->authorizeTeacher($request, $submission->activity);
        $submission->load(['answers.quizQuestion']);
        $submission->recalculateScores();

        return (new SubmissionDetailResource($submission->load(['answers.quizQuestion', 'activity', 'student'])))->response();
    }

    public function photo(Request $request, Submission $submission, SubmissionAnswer $answer): StreamedResponse
    {
        $this->authorizeTeacher($request, $submission->activity);
        abort_unless(
            (int) $answer->submission_id === (int) $submission->id && $answer->photo_path,
            Response::HTTP_NOT_FOUND
        );
        abort_unless(Storage::disk('public')->exists($answer->photo_path), Response::HTTP_NOT_FOUND);

        return Storage::disk('public')->response($answer->photo_path);
    }

    // ── POST /api/teacher/submissions/{submission}/grade ──────────────────────
    // Body: { answers: [{ id, points_awarded, teacher_feedback? }] } — one
    // entry per Photo Upload answer being graded (partial grading is fine;
    // ungraded photo answers just keep the submission flagged for review).
    public function grade(GradeSubmissionRequest $request, Submission $submission): JsonResponse
    {
        $this->authorizeTeacher($request, $submission->activity);

        DB::transaction(function () use ($request, $submission) {
            foreach ($request->validated()['answers'] as $a) {
                SubmissionAnswer::where('id', $a['id'])
                    ->where('submission_id', $submission->id)
                    ->update([
                        'points_awarded'   => $a['points_awarded'],
                        'teacher_feedback' => $a['teacher_feedback'] ?? null,
                        'graded_at'        => now(),
                    ]);
            }
            $submission->recalculateScores();
        });

        $submission->refresh();
        $user = $request->user();

        Activitylog::record(
            action:      'GRADE_SUBMISSION',
            module:      'Activities',
            description: "You graded {$submission->student->full_name}'s submission for \"{$submission->activity->title}\".",
        );

        Auditlog::record(
            action:      Auditlog::ACTION_UPDATE,
            tableName:   'submissions',
            recordId:    $submission->id,
            oldValue:    [],
            newValue:    ['total_score' => $submission->total_score, 'needs_manual_review' => $submission->needs_manual_review],
            description: "Teacher \"{$user->name}\" graded submission #{$submission->id}.",
        );

        return (new SubmissionDetailResource($submission->load(['answers.quizQuestion', 'activity', 'student'])))->response();
    }

    private function authorizeTeacher(Request $request, Activity $activity): void
    {
        abort_unless(
            $request->user() && $activity->teacher_id === $request->user()->id,
            Response::HTTP_FORBIDDEN,
            'You do not own this activity.'
        );
    }
}