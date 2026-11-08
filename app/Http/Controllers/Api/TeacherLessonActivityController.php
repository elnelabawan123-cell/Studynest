<?php

namespace App\Http\Controllers\Api;

use App\Models\Lesson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/teacher/lessons/{lesson}/activity
 * Feeds the new per-student table in LessonPage.tsx's lesson detail modal
 * (Student Name / Viewed Status / Date & Time / Download Status / Date & Time).
 */
class TeacherLessonActivityController extends BaseApiController
{
    public function index(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorizeTeacher($request, $lesson);

        // One row per enrolled student, left-joined against their view/download
        // logs — so students who haven't opened it yet still show up as
        // "Not Viewed", matching SubmissionController::index's roster pattern.
        $enrolled = $lesson->subject?->students()->get() ?? collect();
        $logs = $lesson->activityLogs()->get()->groupBy('student_id');

        $rows = $enrolled->map(function ($student) use ($logs) {
            $studentLogs = $logs->get($student->id, collect());
            $view = $studentLogs->firstWhere('type', 'view');
            $download = $studentLogs->firstWhere('type', 'download');

            return [
                'studentId' => $student->id,
                'studentName' => $student->full_name,
                'viewed' => (bool) $view,
                'viewedAt' => optional($view?->updated_at)->toISOString(),
                'downloaded' => (bool) $download,
                'downloadedAt' => optional($download?->updated_at)->toISOString(),
            ];
        })->values();

        return $this->success($rows);
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
