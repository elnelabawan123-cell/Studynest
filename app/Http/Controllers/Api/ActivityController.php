<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActivityRequest;
use App\Http\Requests\UpdateActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Activitylog;
use App\Models\Auditlog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    // ── GET /api/teacher/activities ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $teacherId = $request->user()->id;

        $query = Activity::with(['subject', 'quizQuestions'])->forTeacher($teacherId);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('subject', fn ($s) => $s->where('name', 'like', "%{$search}%"));
            });
        }

        if ($type   = $request->query('type'))       $query->where('type', $type);
        if ($status = $request->query('status'))     $query->where('status', $status);
        if ($sid    = $request->query('subject_id')) $query->where('subject_id', (int) $sid);

        $activities = $query->latest()->paginate($request->integer('per_page', 25));

        return ActivityResource::collection($activities)->response();
    }

    // ── POST /api/teacher/activities ──────────────────────────────────────────
    public function store(StoreActivityRequest $request): JsonResponse
    {
        $data               = $request->validated();
        $data['teacher_id'] = $request->user()->id;

        if (empty($data['status'])) {
            $data['status'] = $this->computeStatus($data);
        }

        $activity = DB::transaction(function () use ($data) {
            $questions = $data['quiz_questions'] ?? null;
            unset($data['quiz_questions']);

            $activity = Activity::create($data);

            if (is_array($questions)) {
                $this->syncQuizQuestions($activity, $questions);
            }

            return $activity;
        });

        $user = $request->user();

        // Friendly bell text vs. formal audit text
        $notifText = "You created {$activity->type} \"{$activity->title}\".";
        $auditText = "Teacher \"{$user->name}\" created {$activity->type} \"{$activity->title}\" (ID: {$activity->id}).";

        Activitylog::record(
            action:      'CREATE_ACTIVITY',
            module:      'Activities',
            description: $notifText,
        );

        Auditlog::record(
            action:      Auditlog::ACTION_CREATE,
            tableName:   'activities',
            recordId:    $activity->id,
            oldValue:    [],
            newValue:    [
                'title'   => $activity->title,
                'type'    => $activity->type,
                'status'  => $activity->status,
                'subject' => $activity->subject?->name,
            ],
            description: $auditText,
        );

        return (new ActivityResource($activity->load(['subject', 'quizQuestions'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    // ── GET /api/teacher/activities/{activity} ────────────────────────────────
    public function show(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeTeacher($request, $activity);

        // Note: VIEW logs are NOT surfaced in the bell (would be too noisy),
        // but they are kept here for the Activity Log page.
        Activitylog::record(
            action:      'VIEW_ACTIVITY',
            module:      'Activities',
            description: "{$request->user()->name} viewed {$activity->type} \"{$activity->title}\".",
        );

        return (new ActivityResource($activity->load(['subject', 'quizQuestions'])))->response();
    }

    // ── PUT/PATCH /api/teacher/activities/{activity} ──────────────────────────
    public function update(UpdateActivityRequest $request, Activity $activity): JsonResponse
    {
        // Once students can see/answer it (Open) or it's been Closed, its
        // content is locked — editing title/questions/points out from
        // under students who already have it open (or already answered)
        // would be unfair and could invalidate real submissions. Draft and
        // Scheduled (not yet open, no one has seen it) stay editable.
        // Use close()/toggle-status to change availability instead.
        $locked = in_array($activity->resolveStatus(), ['Open', 'Closed'], true)
            || $activity->submissions()->exists();
        abort_if($locked, 423, ucfirst(strtolower($activity->type))
            . ' is already open (or has student submissions) and can no longer be edited. '
            . 'Close it first if you need to stop it, or create a new one instead.');

        $data               = $request->validated();
        $hasQuestionsPayload = $request->has('quiz_questions');

        $oldValues = [
            'title'   => $activity->title,
            'type'    => $activity->type,
            'status'  => $activity->status,
            'subject' => $activity->subject?->name,
        ];

        DB::transaction(function () use ($activity, $data, $hasQuestionsPayload) {
            $questions = $data['quiz_questions'] ?? null;
            unset($data['quiz_questions']);
            $activity->update($data);

            if ($hasQuestionsPayload && is_array($questions)) {
                $this->syncQuizQuestions($activity, $questions);
            }
        });

        $user = $request->user();

        // Friendly bell text vs. formal audit text
        $notifText = "You edited {$activity->type} \"{$activity->title}\".";
        $auditText = "Teacher \"{$user->name}\" edited {$activity->type} \"{$activity->title}\" (ID: {$activity->id}).";

        Activitylog::record(
            action:      'EDIT_ACTIVITY',
            module:      'Activities',
            description: $notifText,
        );

        Auditlog::record(
            action:      Auditlog::ACTION_UPDATE,
            tableName:   'activities',
            recordId:    $activity->id,
            oldValue:    $oldValues,
            newValue:    [
                'title'   => $activity->fresh()->title,
                'type'    => $activity->fresh()->type,
                'status'  => $activity->fresh()->status,
                'subject' => $activity->fresh()->subject?->name,
            ],
            description: $auditText,
        );

        return (new ActivityResource($activity->fresh(['subject', 'quizQuestions'])))->response();
    }

    // ── DELETE /api/teacher/activities/{activity} ─────────────────────────────
    public function destroy(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeTeacher($request, $activity);

        $user      = $request->user();
        $title     = $activity->title;
        $type      = $activity->type;
        $actId     = $activity->id;
        $oldValues = [
            'title'   => $activity->title,
            'type'    => $activity->type,
            'status'  => $activity->status,
            'subject' => $activity->subject?->name,
        ];

        $activity->delete();

        // Friendly bell text vs. formal audit text
        $notifText = "You deleted {$type} \"{$title}\".";
        $auditText = "Teacher \"{$user->name}\" deleted {$type} \"{$title}\" (ID: {$actId}).";

        Activitylog::record(
            action:      'DELETE_ACTIVITY',
            module:      'Activities',
            description: $notifText,
        );

        Auditlog::record(
            action:      Auditlog::ACTION_DELETE,
            tableName:   'activities',
            recordId:    $actId,
            oldValue:    $oldValues,
            newValue:    [],
            description: $auditText,
        );

        return response()->json(['message' => 'Activity deleted.']);
    }

    // ── POST /api/teacher/activities/{activity}/close ─────────────────────────
    public function close(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeTeacher($request, $activity);

        $user      = $request->user();
        $oldStatus = $activity->status;

        $activity->update([
            'status'    => 'Closed',
            'is_closed' => true,
            'closed_at' => now(),
            'closed_by' => $user->id,
        ]);

        // Friendly bell text vs. formal audit text
        $notifText = "You closed {$activity->type} \"{$activity->title}\".";
        $auditText = "Teacher \"{$user->name}\" closed {$activity->type} \"{$activity->title}\" (ID: {$activity->id}).";

        Activitylog::record(
            action:      'CLOSE_ACTIVITY',
            module:      'Activities',
            description: $notifText,
        );

        Auditlog::record(
            action:      Auditlog::ACTION_UPDATE,
            tableName:   'activities',
            recordId:    $activity->id,
            oldValue:    ['status' => $oldStatus],
            newValue:    ['status' => 'Closed'],
            description: $auditText,
        );

        return (new ActivityResource($activity->fresh(['subject', 'quizQuestions'])))->response();
    }

    // ── POST /api/teacher/activities/{activity}/toggle-status ─────────────────
    public function toggleStatus(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeTeacher($request, $activity);

        $user      = $request->user();
        $oldStatus = $activity->status;

        $next = match ($activity->status) {
            'Open'      => 'Closed',
            'Closed'    => 'Open',
            'Scheduled' => 'Open',
            'Draft'     => $this->computeStatus([
                'open_date' => $activity->open_date->format('Y-m-d'),
                'open_time' => $activity->open_time,
                'due_date'  => $activity->due_date->format('Y-m-d'),
                'due_time'  => $activity->due_time,
            ]),
            default => 'Draft',
        };

        $activity->update($next === 'Closed'
            ? ['status' => $next, 'is_closed' => true, 'closed_at' => now(), 'closed_by' => $user->id]
            : ['status' => $next, 'is_closed' => false, 'closed_at' => null, 'closed_by' => null]);

        // Friendly bell text vs. formal audit text
        $notifText = "You changed {$activity->type} \"{$activity->title}\" from {$oldStatus} to {$next}.";
        $auditText = "Teacher \"{$user->name}\" changed {$activity->type} \"{$activity->title}\" (ID: {$activity->id}) status from {$oldStatus} to {$next}.";

        Activitylog::record(
            action:      'TOGGLE_ACTIVITY',
            module:      'Activities',
            description: $notifText,
        );

        Auditlog::record(
            action:      Auditlog::ACTION_UPDATE,
            tableName:   'activities',
            recordId:    $activity->id,
            oldValue:    ['status' => $oldStatus],
            newValue:    ['status' => $next],
            description: $auditText,
        );

        return (new ActivityResource($activity->fresh(['subject', 'quizQuestions'])))->response();
    }

    /* ─── Helpers ────────────────────────────────────────────────────────────── */

   private function computeStatus(array $data): string
{
    $now  = now();
    $open = \Carbon\Carbon::parse($data['open_date'] . ' ' . $data['open_time']);

    // due_date no longer closes anything — only close()/toggleStatus()
    // (teacher actions) can set status to 'Closed'. due_date now only
    // feeds into Submission::is_late at submit time.
    if ($open->gt($now)) return 'Scheduled';
    return 'Open';
}

    private function syncQuizQuestions(Activity $activity, array $questions): void
    {
        $activity->quizQuestions()->delete();

        foreach (array_values($questions) as $i => $q) {
            $activity->quizQuestions()->create([
                'order'           => $i,
                'question'        => $q['question'],
                'answer_type'     => $q['answer_type'],
                'checking_mode'  => $q['checking_mode'] ?? ($q['answer_type'] === 'Photo' ? 'Manual' : 'Auto'),
                'choices'         => $q['choices']         ?? null,
                'correct_answer'  => $q['correct_answer'] ?? null,
                'points'          => $q['points']          ?? 10,
                'attachment_path' => $q['attachment_path'] ?? null,
                'attachment_type' => $q['attachment_type'] ?? null,
            ]);
        }
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