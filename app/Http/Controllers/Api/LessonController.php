<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLessonRequest;
use App\Http\Requests\UpdateLessonRequest;
use App\Http\Resources\LessonResource;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Support\MediaUploadLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LessonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teacherId = $request->user()->id;

        $query = Lesson::with('subject')->forTeacher($teacherId);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhereHas('subject', fn ($s) => $s->where('name', 'like', "%{$search}%"));
            });
        }

        // 'status' now only ever resolves to scheduled|published â€” draft is gone.
        if ($status = $request->query('status')) {
            $query->where('status', strtolower($status));
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', (int) $subjectId);
        }

        $lessons = $query->latest()->paginate($request->integer('per_page', 25));

        return LessonResource::collection($lessons)->response();
    }

    // â”€â”€ POST /api/teacher/lessons â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function store(StoreLessonRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['teacher_id'] = $request->user()->id;
        $data['status']     = $data['status'] ?? 'scheduled';

        if ($request->hasFile('file')) {
            $file              = $request->file('file');
            if ($error = $this->validateMediaLimits($file)) {
                return $error;
            }
            $path              = $file->store('lessons', 'public');
            $data['file_path'] = $path;
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_size'] = $file->getSize();
            $data['mime_type'] = $file->getMimeType();
            $data['type']      = Lesson::detectType($file->getClientOriginalName());
        }

        if ($data['status'] === 'published') {
            $data['published_at'] = now();
            $data['scheduled_at'] = null;
        }

        $lesson = Lesson::create($data);
        $user   = $request->user();

        $isPublished = $lesson->status === 'published';

        // Friendly bell text vs. formal audit text
        $notifText = $isPublished
            ? "You uploaded and published lesson \"{$lesson->title}\"."
            : "You uploaded lesson \"{$lesson->title}\" â€” scheduled for {$lesson->scheduled_at?->format('M j, Y g:i A')}.";
        $auditText = $isPublished
            ? "Teacher \"{$user->name}\" uploaded and published lesson \"{$lesson->title}\" (ID: {$lesson->id})."
            : "Teacher \"{$user->name}\" uploaded lesson \"{$lesson->title}\" (ID: {$lesson->id}), scheduled for {$lesson->scheduled_at?->format('M j, Y g:i A')}.";

        ActivityLog::record(
            action:      'UPLOAD_LESSON',
            module:      'Lessons',
            description: $notifText,
        );

        AuditLog::record(
            action:      AuditLog::ACTION_CREATE,
            tableName:   'lessons',
            recordId:    $lesson->id,
            oldValue:    [],
            newValue:    [
                'title'        => $lesson->title,
                'type'         => $lesson->type,
                'status'       => $lesson->status,
                'scheduled_at' => $lesson->scheduled_at,
                'subject'      => $lesson->subject?->name,
            ],
            description: $auditText,
        );

        return (new LessonResource($lesson->load('subject')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeTeacher($request, $lesson);

        // Note: VIEW logs are NOT surfaced in the bell (would be too noisy),
        // but they are kept here for the Activity Log page.
        ActivityLog::record(
            action:      'VIEW_LESSON',
            module:      'Lessons',
            description: "{$request->user()->name} viewed lesson \"{$lesson->title}\".",
        );

        return (new LessonResource($lesson->load('subject')))->response();
    }

    // â”€â”€ PUT/PATCH /api/teacher/lessons/{lesson} â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function update(UpdateLessonRequest $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validated();

        $oldValues = [
            'title'        => $lesson->title,
            'type'         => $lesson->type,
            'status'       => $lesson->status,
            'scheduled_at' => $lesson->scheduled_at,
            'subject'      => $lesson->subject?->name,
        ];

        // Once a lesson is published, its schedule is locked â€” only content
        // (title, description, subject, type, file) may still change.
        $wasAlreadyPublished = $lesson->status === 'published';
        if ($wasAlreadyPublished) {
            unset($data['status'], $data['scheduled_at']);
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            if ($error = $this->validateMediaLimits($file)) {
                return $error;
            }
            if ($lesson->file_path) {
                Storage::disk('public')->delete($lesson->file_path);
            }
            $data['file_path'] = $file->store('lessons', 'public');
            $data['file_name'] = $file->getClientOriginalName();
            $data['file_size'] = $file->getSize();
            $data['mime_type'] = $file->getMimeType();
            $data['type']      = Lesson::detectType($file->getClientOriginalName());
        }

        $wasPublishedNow = false;
        if (!$wasAlreadyPublished && ($data['status'] ?? null) === 'published') {
            $data['published_at'] = now();
            $data['scheduled_at'] = null;
            $wasPublishedNow      = true;
        }

        $lesson->update($data);
        $user = $request->user();

        // Friendly bell text vs. formal audit text
        $notifText = $wasPublishedNow
            ? "You edited and published lesson \"{$lesson->title}\"."
            : ($wasAlreadyPublished
                ? "You edited lesson \"{$lesson->title}\"."
                : "You edited lesson \"{$lesson->title}\" â€” scheduled for {$lesson->scheduled_at?->format('M j, Y g:i A')}.");

        $auditText = $wasPublishedNow
            ? "Teacher \"{$user->name}\" edited and published lesson \"{$lesson->title}\" (ID: {$lesson->id})."
            : "Teacher \"{$user->name}\" edited lesson \"{$lesson->title}\" (ID: {$lesson->id}).";

        ActivityLog::record(
            action:      $wasPublishedNow ? 'PUBLISH_LESSON' : 'EDIT_LESSON',
            module:      'Lessons',
            description: $notifText,
        );

        AuditLog::record(
            action:      AuditLog::ACTION_UPDATE,
            tableName:   'lessons',
            recordId:    $lesson->id,
            oldValue:    $oldValues,
            newValue:    [
                'title'        => $lesson->title,
                'type'         => $lesson->type,
                'status'       => $lesson->status,
                'scheduled_at' => $lesson->scheduled_at,
                'subject'      => $lesson->subject?->name,
            ],
            description: $auditText,
        );

        return (new LessonResource($lesson->fresh('subject')))->response();
    }

    private function validateMediaLimits(\Illuminate\Http\UploadedFile $file): ?JsonResponse
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)
            && $file->getSize() > MediaUploadLimits::MAX_IMAGE_BYTES) {
            return response()->json(['message' => 'Photos must be 5MB or smaller.'], 422);
        }

        if (in_array($extension, ['mp4', 'mov', 'avi'], true)) {
            if ($file->getSize() > MediaUploadLimits::MAX_VIDEO_BYTES) {
                return response()->json(['message' => 'Videos must be 50MB or smaller.'], 422);
            }

            $duration = MediaUploadLimits::videoDurationSeconds($file->getRealPath());
            if ($duration === null) {
                return response()->json([
                    'message' => 'Could not read that video\'s length â€” please try a different file.',
                ], 422);
            }
            if ($duration > MediaUploadLimits::MAX_VIDEO_SECONDS) {
                return response()->json([
                    'message' => 'Videos must be 5 minutes or shorter. This one is ' .
                        gmdate('i:s', (int) round($duration)) . ' long.',
                ], 422);
            }
        }

        return null;
    }

    // â”€â”€ DELETE /api/teacher/lessons/{lesson} â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function destroy(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeTeacher($request, $lesson);

        $user      = $request->user();
        $title     = $lesson->title;
        $lessonId  = $lesson->id;
        $oldValues = [
            'title'        => $lesson->title,
            'type'         => $lesson->type,
            'status'       => $lesson->status,
            'scheduled_at' => $lesson->scheduled_at,
            'subject'      => $lesson->subject?->name,
        ];

        if ($lesson->file_path) {
            Storage::disk('public')->delete($lesson->file_path);
        }

        $lesson->delete();

        // Friendly bell text vs. formal audit text
        $notifText = "You deleted lesson \"{$title}\".";
        $auditText = "Teacher \"{$user->name}\" deleted lesson \"{$title}\" (ID: {$lessonId}).";

        ActivityLog::record(
            action:      'DELETE_LESSON',
            module:      'Lessons',
            description: $notifText,
        );

        AuditLog::record(
            action:      AuditLog::ACTION_DELETE,
            tableName:   'lessons',
            recordId:    $lessonId,
            oldValue:    $oldValues,
            newValue:    [],
            description: $auditText,
        );

        return response()->json(['message' => 'Lesson deleted.']);
    }

    // NOTE: the manual `publish()` endpoint has been intentionally removed.
    // Publishing is now fully automatic â€” see App\Console\Commands\PublishScheduledLessons,
    // which is scheduled to run every minute in routes/console.php. Remove any
    // corresponding `POST /teacher/lessons/{lesson}/publish` route as well.

    // â”€â”€ POST /api/lessons/{lesson}/view (student) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function trackView(Lesson $lesson): JsonResponse
    {
        $lesson->increment('views');
        $user = auth()->user();

        if ($user) {
            // Student action â€” logged with student's user_id, so not surfaced in teacher's bell.
            ActivityLog::record(
                action:      'VIEW_LESSON',
                module:      'Lessons',
                description: "{$user->name} viewed lesson \"{$lesson->title}\".",
            );
        }

        return response()->json(['views' => $lesson->fresh()->views]);
    }

    // â”€â”€ POST /api/lessons/{lesson}/download (student) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    public function trackDownload(Lesson $lesson): JsonResponse
    {
        $lesson->increment('downloads');
        $user = auth()->user();

        if ($user) {
            ActivityLog::record(
                action:      'DOWNLOAD_LESSON',
                module:      'Lessons',
                description: "{$user->name} downloaded lesson \"{$lesson->title}\".",
            );
        }

        return response()->json(['downloads' => $lesson->fresh()->downloads]);
    }

    private function authorizeTeacher(Request $request, Lesson $lesson): void
    {
        abort_unless(
            $request->user() && $lesson->teacher_id === $request->user()->id,
            Response::HTTP_FORBIDDEN,
            'You do not own this lesson.'
        );
    }
}