<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use App\Models\LessonActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class StudentLessonController extends BaseApiController
{
    // GET /api/student/lessons?subject_id=
    public function index(Request $request): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 403, 'No student profile found for this account.');

        $lessons = Lesson::published()
            ->whereHas('subject.students', fn ($q) => $q->where('students.id', $studentProfile->id))
            ->when($request->subject_id, fn ($q) => $q->where('subject_id', $request->subject_id))
            ->with('subject:id,name,code', 'teacher:id,name')
            ->latest('published_at')
            ->get();

        // NEW: tell the student app whether THEY have viewed each lesson —
        // needed for the "Not Viewed / Viewed" status badge on that screen.
        $viewedLessonIds = LessonActivityLog::where('student_id', $studentProfile->id)
            ->where('type', 'view')
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->pluck('lesson_id')
            ->all();

        return $this->success($lessons->map(fn ($l) => [
            'id' => $l->id,
            'title' => $l->title,
            'description' => $l->description,
            'type' => $l->type,          // Slides | PPT | Video | PDF
            'file_url' => $l->file_url,  // kept for non-PDF/video (external open)
            'subject' => $l->subject->name,
            'teacher' => $l->teacher->name,
            'views' => $l->views,
            'published_at' => $l->published_at,
            'viewed' => in_array($l->id, $viewedLessonIds, true),
        ]));
    }

    /**
     * GET /api/student/lessons/{lesson}/stream
     *
     * Why this exists instead of just using file_url directly:
     * 1. On Flutter Web, fetching the raw storage URL cross-origin (a
     *    different port than the API) gets blocked by CORS — Laravel's
     *    default cors.php only covers `api/*`, not static storage files.
     *    This endpoint lives under `api/*` so it's covered automatically.
     * 2. It lets us enforce enrollment before serving the file, instead
     *    of relying on the storage URL being "security by obscurity".
     * 3. It sets Content-Type/Content-Disposition explicitly, which some
     *    PDF viewer packages depend on to render at all.
     *
     * The Flutter side must pass the auth token as a header (SfPdfViewer.
     * network supports a `headers` param) since this route is behind
     * auth:sanctum, same as every other /api/student/* route.
     */
    public function stream(Request $request, Lesson $lesson): Response
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 403, 'No student profile found for this account.');

        $enrolled = $lesson->subject->students()->where('students.id', $studentProfile->id)->exists();
        abort_unless($enrolled, 403, 'This lesson is not assigned to your class.');
        abort_unless($lesson->status === 'published', 403, 'This lesson is not published yet.');
        abort_if(!$lesson->file_path, 404, 'No file attached to this lesson.');

        $disk = Storage::disk('public');
        abort_unless($disk->exists($lesson->file_path), 404, 'File not found.');

        $content = $disk->get($lesson->file_path);

        return response($content, 200, [
            'Content-Type' => $lesson->mime_type ?? 'application/pdf',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'inline; filename="' . ($lesson->file_name ?? 'lesson') . '"',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * GET /api/student/lessons/{lesson}/preview-link
     * Signed-URL twin of downloadLink() below, but for in-app PDF/video
     * preview instead of download. Exists because SfPdfViewer.network's
     * `headers` param (used to send the auth Bearer token) forces a CORS
     * PREFLIGHT (OPTIONS) request on Flutter Web for any custom header —
     * and that preflight silently fails if cors.php doesn't explicitly
     * allow the Authorization header, producing a blank viewer with no
     * visible error. A signed URL needs no header at all, so no
     * preflight is triggered — same trick as downloads, just `inline`
     * instead of `attachment`.
     */
    public function previewLink(Request $request, Lesson $lesson): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 403, 'No student profile found for this account.');

        $enrolled = $lesson->subject->students()->where('students.id', $studentProfile->id)->exists();
        abort_unless($enrolled, 403, 'This lesson is not assigned to your class.');

        $relativeUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'lessons.signed-preview',
            now()->addMinutes(15), // longer than download — a student may sit reading it
            ['lesson' => $lesson->id],
            false,
        );

        return $this->success([
            'url' => rtrim($request->getSchemeAndHttpHost(), '/') . $relativeUrl,
        ]);
    }

    /**
     * GET /api/lessons/{lesson}/signed-preview — signed-URL twin of
     * signedDownload() below, `inline` instead of `attachment`.
     */
    public function signedPreview(Lesson $lesson): Response
    {
        abort_unless($lesson->status === 'published', 403, 'This lesson is not published yet.');
        abort_if(!$lesson->file_path, 404, 'No file attached to this lesson.');

        $disk = Storage::disk('public');
        abort_unless($disk->exists($lesson->file_path), 404, 'File not found.');

        $content = $disk->get($lesson->file_path);

        return response($content, 200, [
            'Content-Type' => $lesson->mime_type ?? 'application/pdf',
            'Content-Length' => (string) strlen($content), // FIXED: was missing — Syncfusion needs this to render
            'Content-Disposition' => 'inline; filename="' . ($lesson->file_name ?? 'lesson') . '"',
            'Access-Control-Allow-Origin' => '*',
            // Removed 'Accept-Ranges: bytes' — we don't actually implement
            // 206 partial-content responses, so claiming range support
            // was misleading and could make some viewers wait on a range
            // request that would never come back correctly.
        ]);
    }

    /**
     * GET /api/student/lessons/{lesson}/download-link
     * Returns a short-lived SIGNED url (not behind auth:sanctum) that the
     * Flutter app can hand to url_launcher to open in the system browser.
     * Needed because url_launcher can't attach an Authorization header —
     * a signed URL is Laravel's standard way to authorize a request
     * without one.
     */
    public function downloadLink(Request $request, Lesson $lesson): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 403, 'No student profile found for this account.');

        $enrolled = $lesson->subject->students()->where('students.id', $studentProfile->id)->exists();
        abort_unless($enrolled, 403, 'This lesson is not assigned to your class.');

        $relativeUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'lessons.signed-download',
            now()->addMinutes(5),
            ['lesson' => $lesson->id],
            false,
        );

        return $this->success([
            'url' => rtrim($request->getSchemeAndHttpHost(), '/') . $relativeUrl,
        ]);
    }

    /**
     * GET /api/lessons/{lesson}/signed-download  (still under /api since
     * it's registered in api.php, but NOT inside the auth:sanctum group —
     * validated by Laravel's `signed` middleware instead, via the URL
     * generated in downloadLink() above.)
     */
    public function signedDownload(Lesson $lesson): Response
    {
        abort_unless($lesson->status === 'published', 403, 'This lesson is not published yet.');
        abort_if(!$lesson->file_path, 404, 'No file attached to this lesson.');

        $disk = Storage::disk('public');
        abort_unless($disk->exists($lesson->file_path), 404, 'File not found.');

        $content = $disk->get($lesson->file_path);

        return response($content, 200, [
            'Content-Type' => $lesson->mime_type ?? 'application/octet-stream',
            'Content-Length' => (string) strlen($content),
            'Content-Disposition' => 'attachment; filename="' . ($lesson->file_name ?? 'lesson') . '"',
        ]);
    }

    /**
     * POST /api/student/lessons/{lesson}/view
     * Called once when the student opens a lesson / the PDF preview loads.
     * Upserts a log row (repeat views just bump updated_at) AND bumps the
     * aggregate `views` counter the teacher dashboard already shows.
     */
    public function trackView(Request $request, Lesson $lesson): JsonResponse
    {
        return $this->trackActivity($request, $lesson, 'view');
    }

    /**
     * POST /api/student/lessons/{lesson}/download
     * Called when the student taps the explicit Download button.
     */
    public function trackDownload(Request $request, Lesson $lesson): JsonResponse
    {
        return $this->trackActivity($request, $lesson, 'download');
    }

    private function trackActivity(Request $request, Lesson $lesson, string $type): JsonResponse
    {
        $studentProfile = $request->user()->studentProfile;
        abort_unless($studentProfile, 403, 'No student profile found for this account.');

        $enrolled = $lesson->subject->students()->where('students.id', $studentProfile->id)->exists();
        abort_unless($enrolled, 403, 'This lesson is not assigned to your class.');

        $wasNew = !LessonActivityLog::where([
            'lesson_id' => $lesson->id,
            'student_id' => $studentProfile->id,
            'type' => $type,
        ])->exists();

        LessonActivityLog::updateOrCreate(
            ['lesson_id' => $lesson->id, 'student_id' => $studentProfile->id, 'type' => $type],
            [] // no extra fields to set — just bumps updated_at on repeat
        );

        // Aggregate counter only increments on the FIRST view/download per
        // student, matching "Students Viewed" being a per-student count,
        // not a total-hits count.
        if ($wasNew) {
            $lesson->increment($type === 'view' ? 'views' : 'downloads');
        }

        return $this->success(null, ucfirst($type) . ' recorded.');
    }
}
