<?php

namespace App\Http\Controllers\Api;

use App\Models\Activity;
use App\Models\Submission;
use App\Support\StorageFileUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentActivityController extends BaseApiController
{
    // GET /api/student/activities?subject_id=&type=Assignment
    public function index(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 403, 'No student profile found for this account.');

        $activities = Activity::with('subject:id,name,code')
            ->whereHas('subject.students', fn ($q) => $q->where('students.id', $studentProfile->id))
            ->where('status', '!=', 'Draft') // never show a teacher's unfinished draft
            ->when($request->subject_id, fn ($q) => $q->where('subject_id', $request->subject_id))
            ->when($request->type, fn ($q) => $q->where('type', $request->type)) // Quiz | Assignment | Activity
            ->orderBy('due_date')
            ->get();

        // Batched, not per-row — list cards get real per-student status
        // without N extra API calls.
        $submissions = Submission::whereIn('activity_id', $activities->pluck('id'))
            ->where('student_id', $request->user()->id)
            ->get()
            ->keyBy('activity_id');

        return $this->success($activities->map(function ($a) use ($submissions) {
            $submission = $submissions->get($a->id);
            // No separate is_closed column — 'Closed' is a value of the
            // real `status` column, set only by
            // ActivityController::close()/toggleStatus(). Using
            // $a->is_closed here was always false (undefined attribute),
            // which silently broke "Task Closed" — fixed to check status.
            $isClosed = $a->status === 'Closed';

            return [
                'id' => $a->id,
                'title' => $a->title,
                'description' => $a->description,
                'type' => $a->type,
                // resolveStatus() reflects Draft/Scheduled/Open/Closed —
                // Closed now means "teacher closed it" — never due_date
                // (see ActivityController::computeStatus() fix).
                'status' => $a->resolveStatus(),
                'is_closed' => $isClosed,
                'allow_resubmission' => (bool) $a->allow_resubmission,
                'subject' => $a->subject->name,
                'open_date' => $a->open_date,
                'open_time' => $a->open_time,
                'created_at' => $a->created_at,
                'due_date' => $a->due_date,
                'due_time' => $a->due_time,
                'total_points' => $a->total_points,
                'time_limit' => $a->time_limit,

                // What the Subject Page badge should actually read from —
                // Not Started | In Progress | Submitted | Late Submission |
                // Graded | Task Closed.
                'student_status' => $submission?->displayStatus()
                    ?? ($isClosed ? 'Task Closed' : 'Not Started'),
                'is_late' => (bool) ($submission?->is_late),
                'completion_percentage' => $submission?->completion_percentage ?? 0,
                'total_score' => $submission?->total_score,
            ];
        }));
    }

    // GET /api/student/activities/{activity}
    public function show(Request $request, Activity $activity): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 403, 'No student profile found for this account.');

        $enrolled = $activity->subject->students()->where('students.id', $studentProfile->id)->exists();
        abort_unless($enrolled, 403, 'This is not assigned to your class.');
        abort_if($activity->status === 'Draft', 403, 'This is not published yet.');

        // Needed below regardless of type — quizQuestions() is a generic
        // table used for Quiz questions AND Assignment/Activity steps
        // (see Activity::quizQuestions() / QuizQuestion::answer_type).
        $activity->loadMissing('quizQuestions');
        
            // Older Assignment/Activity records were created before submission
            // steps were persisted. Give those records one stable question ID so
            // existing students can submit without requiring the teacher to edit
            // and save the activity again.
            if ($activity->type !== 'Quiz' && $activity->quizQuestions->isEmpty()) {
                $activity->quizQuestions()->create([
                    'order' => 0,
                    'question' => $activity->description ?: $activity->title,
                    'answer_type' => $activity->allow_photo_answer && !$activity->allow_text_answer ? 'Photo' : 'Text',
                    'points' => $activity->total_points,
                ]);
                $activity->load('quizQuestions');
            }

        $submission = Submission::where('activity_id', $activity->id)
            ->where('student_id', $request->user()->id)
            ->first();

        $isClosed = $activity->status === 'Closed';
        $resolvedStatus = $activity->resolveStatus();
        // A Quiz's questions must stay hidden until it actually opens —
        // otherwise a student could read (and look up) every question
        // before the scheduled start. Assignment/Activity steps aren't
        // hidden pre-open since there's no "answer lookup" concern there.
        $hideQuestions = $activity->type === 'Quiz' && $resolvedStatus === 'Scheduled';

        $data = [
            'id' => $activity->id,
            'title' => $activity->title,
            'description' => $activity->description,
            'type' => $activity->type,
            'status' => $activity->resolveStatus(),
            'is_closed' => $isClosed,
            'allow_resubmission' => (bool) $activity->allow_resubmission,
            'subject' => $activity->subject->name,
            'due_date' => $activity->due_date,
            'due_time' => $activity->due_time,
            'total_points' => $activity->total_points,
            'time_limit' => $activity->time_limit,
            'allow_photo_answer' => $activity->allow_photo_answer,
            'allow_text_answer' => $activity->allow_text_answer,
            'attachment_path' => $activity->attachment_path,
            'attachment_url' => StorageFileUrl::forPath($activity->attachment_path),
            'attachment_type' => $activity->attachment_type,

            // Resume support — quiz_screen_G4.dart jumps straight to this
            // index and prefills answers from /submission instead of
            // starting over at question 0.
            'student_status' => $submission?->displayStatus()
                ?? ($isClosed ? 'Task Closed' : 'Not Started'),
            'is_late' => (bool) ($submission?->is_late),
            'current_question_index' => $submission?->current_question_index ?? 0,
            'completion_percentage' => $submission?->completion_percentage ?? 0,
        ];

        // Send question/step rows for ALL types, not just Quiz — Assignment
        // and Activity steps live in the same quizQuestions table, just with
        // answer_type Text/Photo instead of Multiple Choice. Never send
        // correct_answer to the student regardless of type.
        $data['questions'] = $hideQuestions ? [] : $activity->quizQuestions->map(fn ($q) => [
            'id' => $q->id,
            'question' => $q->question,
            'answer_type' => $q->answer_type, // Multiple Choice | Text | Photo
            'checking_mode' => $q->checking_mode ?? ($q->answer_type === 'Photo' ? 'Manual' : 'Auto'),
            'choices' => $q->choices,
            'points' => $q->points,
            'attachment_path' => $q->attachment_path,
            'attachment_url' => StorageFileUrl::forPath($q->attachment_path),
            'attachment_type' => $q->attachment_type,
        ]);
        $data['not_open_yet'] = $hideQuestions;

        return $this->success($data);
    }
}