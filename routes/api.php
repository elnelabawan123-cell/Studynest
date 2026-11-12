<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentAccountController;
use App\Http\Controllers\Api\StudentPasswordController;
use App\Http\Controllers\Api\TeacherStudentController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeacherAccountController;
use App\Http\Controllers\Api\TeacherRegistrationController;
use App\Http\Controllers\Api\TeacherMeController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AdminpasswordController;
use App\Http\Controllers\Api\AdminsettingController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AdminnotificationController;
use App\Http\Controllers\Api\SchoolYearController;
use App\Http\Controllers\Api\TeacherreportController;
use App\Http\Controllers\Api\PrincipalReportController;
use App\Http\Controllers\Api\StudentBulkController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\StudentSubmissionController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\StudentActivityController;
use App\Http\Controllers\Api\StudentLessonController;
use App\Http\Controllers\Api\AccountsController;
use App\Http\Controllers\Api\TeacherLessonActivityController;
use App\Http\Controllers\Api\StudentGameRewardController;
use App\Http\Controllers\Api\GameContentController;
use App\Http\Controllers\Api\StudentWalletController;
use App\Http\Controllers\Api\StudentShopController;
use App\Http\Controllers\Api\StudentInventoryController;
use App\Http\Controllers\Api\AiVoiceController;


// ── Public ───────────────────────────────────────────────────
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1')->name('login');
Route::post('/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:6,1');
Route::get('/settings/public', [TeacherRegistrationController::class, 'options']);
Route::post('/teacher-registration-requests', [TeacherRegistrationController::class, 'register'])->middleware('throttle:5,1');

// ── Protected ────────────────────────────────────────────────
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get ('/me',     [AuthController::class, 'me'])->middleware('maintenance');
    Route::put ('/me',     [AuthController::class, 'updateMe'])->middleware('maintenance');
    Route::post('/ai-voice/synthesize', [AiVoiceController::class, 'synthesize'])->middleware('throttle:60,1');

    // ── Student (Flutter) ─────────────────────────────────────
    Route::middleware(['role:student', 'maintenance', 'throttle:student-api'])->group(function () {
        Route::post('/student/change-password', [StudentPasswordController::class, 'change']);

        // Activities and lessons
        Route::get('/student/activities',              [StudentActivityController::class, 'index']);
        Route::get('/student/assignments',             [StudentActivityController::class, 'assignments']);
        Route::get('/student/quizzes',                 [StudentActivityController::class, 'quizzes']);
        Route::get('/student/activities/{activity}',   [StudentActivityController::class, 'show']);
        Route::get('/student/lessons',                 [StudentLessonController::class, 'index']);
        Route::get('/student/subjects',                [SubjectController::class, 'studentSubjects']);
        Route::get('/student/subjects/available',      [SubjectController::class, 'availableForStudent']);
        Route::post('/student/subjects/{id}/enroll',   [SubjectController::class, 'enrollStudent']);
        Route::get('/student/game-rewards',            [StudentGameRewardController::class, 'index']);
        Route::post('/student/game-rewards',           [StudentGameRewardController::class, 'store']);
        Route::get('/student/game-progress',           [StudentGameRewardController::class, 'progressIndex']);
        Route::post('/student/game-progress',          [StudentGameRewardController::class, 'saveProgress']);
        Route::get('/student/game-contents',           [GameContentController::class, 'indexForStudent']);
        Route::get('/student/game-content/{gameKey}',  [GameContentController::class, 'showForStudent']);

        // Star economy: wallet, shop, inventory
        Route::get('/student/wallet',                  [StudentWalletController::class, 'balance']);
        Route::get('/student/wallet/transactions',      [StudentWalletController::class, 'transactions']);
        Route::get('/student/shop/items',               [StudentShopController::class, 'items']);
        Route::post('/student/shop/purchase',           [StudentShopController::class, 'purchase']);
        Route::post('/student/shop/equip-avatar',       [StudentShopController::class, 'equipAvatar']);
        Route::get('/student/inventory',                [StudentInventoryController::class, 'index']);
        Route::post('/student/inventory/consume',       [StudentInventoryController::class, 'consume']);

        Route::middleware('throttle:20,1')->group(function () {
            // PDF/video streaming + per-student view/download tracking
            Route::get ('/student/lessons/{lesson}/stream',   [StudentLessonController::class, 'stream']);
            Route::get ('/student/lessons/{lesson}/preview-link',  [StudentLessonController::class, 'previewLink']);
            Route::get ('/student/lessons/{lesson}/download-link', [StudentLessonController::class, 'downloadLink']);
            Route::post('/student/lessons/{lesson}/view',     [StudentLessonController::class, 'trackView']);
            Route::post('/student/lessons/{lesson}/download', [StudentLessonController::class, 'trackDownload']);
        });

        // Activity submissions
        Route::post('/student/activities/{activity}/start',      [StudentSubmissionController::class, 'start']);
        Route::post('/student/activities/{activity}/submit',     [StudentSubmissionController::class, 'submit']);
        Route::get ('/student/activities/{activity}/submission', [StudentSubmissionController::class, 'show']);

        Route::post('student/activities/{activity}/answer', [StudentSubmissionController::class, 'saveAnswer']);

    });

    // ── Admin only ────────────────────────────────────────────
    Route::middleware('role:admin')->group(function () {

        // Legacy student records
        Route::apiResource('students', StudentController::class);

        // Student accounts — static routes BEFORE {id}
        Route::post  ('student-accounts/promote-grades',      [StudentAccountController::class, 'promoteGrades']); // kept for manual testing
        Route::get   ('student-accounts',                     [StudentAccountController::class, 'index']);
        Route::post  ('student-accounts',                     [StudentAccountController::class, 'store']);
        Route::put   ('student-accounts/{id}',                [StudentAccountController::class, 'update']);
        Route::delete('student-accounts/{id}',                [StudentAccountController::class, 'destroy']);
        Route::post  ('student-accounts/{id}/reset-password', [StudentAccountController::class, 'resetPassword']);
        Route::patch ('student-accounts/{id}/status',         [StudentAccountController::class, 'updateStatus']);
        Route::post  ('student-accounts/{id}/restore',        [StudentAccountController::class, 'restore']);

        Route::get('admin/students/{studentId}/teachers', [TeacherStudentController::class, 'studentTeachers']);

        // Teacher profiles
        Route::apiResource('teachers', TeacherController::class);

        // Teacher accounts
        Route::get   ('teacher-registration-requests', [TeacherRegistrationController::class, 'index']);
        Route::post  ('teacher-registration-requests/{id}/approve', [TeacherRegistrationController::class, 'approve']);
        Route::post  ('teacher-registration-requests/{id}/reject', [TeacherRegistrationController::class, 'reject']);
        Route::get   ('teacher-accounts',                     [TeacherAccountController::class, 'index']);
        Route::post  ('teacher-accounts',                     [TeacherAccountController::class, 'store']);
        Route::put   ('teacher-accounts/{id}',                [TeacherAccountController::class, 'update']);
        Route::delete('teacher-accounts/{id}',                [TeacherAccountController::class, 'destroy']);
        Route::patch ('teacher-accounts/{id}/status',         [TeacherAccountController::class, 'updateStatus']);
        Route::post  ('teacher-accounts/{id}/restore',        [TeacherAccountController::class, 'restore']);

        // All Accounts (Students & Teachers)
        Route::get   ('admin/accounts',           [AccountsController::class, 'index']);
        Route::post  ('admin/accounts/{id}/reset-password', [AccountsController::class, 'resetPassword']);

        // Subjects — static paths BEFORE /{id}
        Route::get   ('admin/subjects/names',                             [SubjectController::class, 'names']);
        Route::get   ('admin/subjects/available-grades',                  [SubjectController::class, 'availableGrades']);
        Route::get   ('admin/subjects',                                   [SubjectController::class, 'index']);
        Route::post  ('admin/subjects',                                   [SubjectController::class, 'store']);
        Route::put   ('admin/subjects/{id}',                              [SubjectController::class, 'update']);
        Route::patch ('admin/subjects/{id}/status',                       [SubjectController::class, 'updateStatus']);
        Route::post  ('admin/subjects/{id}/assign-teacher',               [SubjectController::class, 'assignTeacher']);
        Route::delete('admin/subjects/{id}/unassign-teacher/{teacherId}', [SubjectController::class, 'unassignTeacher']);

        // Sections
        Route::get   ('admin/sections',      [SectionController::class, 'index']);
        Route::post  ('admin/sections',      [SectionController::class, 'store']);
        Route::put   ('admin/sections/{id}', [SectionController::class, 'update']);
        Route::delete('admin/sections/{id}', [SectionController::class, 'destroy']);

        // School Year — static paths BEFORE parameterised
        Route::get ('admin/school-years',                  [SchoolYearController::class, 'index']);
        Route::get ('admin/school-year/promotion-history', [SchoolYearController::class, 'promotionHistory']);
        Route::get ('admin/school-year',                   [SchoolYearController::class, 'show']);
        Route::put ('admin/school-year/end-date',          [SchoolYearController::class, 'updateEndDate']);
        Route::post('admin/school-year/promote',           [SchoolYearController::class, 'promote']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('summary',             [ReportController::class, 'summary']);
            Route::get('student-performance', [ReportController::class, 'studentPerformance']);
            Route::get('teacher-activity',    [ReportController::class, 'teacherActivity']);
            Route::get('system-usage',        [ReportController::class, 'systemUsage']);
        });

        // Activity & Audit Logs
        Route::get('activity-logs',         [ActivityLogController::class, 'index']);
        Route::get('activity-logs/summary', [ActivityLogController::class, 'summary']);
        Route::get('audit-logs',            [AuditLogController::class, 'index']);
        Route::get('audit-logs/stats',      [AuditLogController::class, 'stats']);
        Route::post('/activity-logs', [ActivityLogController::class, 'store']);

        // Admin Settings
        Route::get ('settings',                  [AdminsettingController::class, 'index']);
        Route::put ('settings',                  [AdminsettingController::class, 'update']);
        Route::get ('settings/keys',             [AdminsettingController::class, 'availableKeys']);
        Route::put ('settings/maintenance-mode', [AdminsettingController::class, 'toggleMaintenance']);
        Route::get ('settings/{key}',            [AdminsettingController::class, 'show']);
        Route::put ('settings/{key}',            [AdminsettingController::class, 'updateSingle']);

        // Change Admin Password
        Route::post('change-password', [AdminpasswordController::class, 'changePassword']);

        // Admin Notifications
        Route::get ('admin/notifications',          [AdminnotificationController::class, 'index']);
        Route::post('admin/notifications/read-all', [AdminnotificationController::class, 'markAllRead']);

        Route::prefix('admin/reports/principal')->group(function () {
    Route::get('school-overview',     [PrincipalReportController::class, 'schoolOverview']);
    Route::get('teacher-performance', [PrincipalReportController::class, 'teacherPerformance']);
    Route::get('subject-coverage',    [PrincipalReportController::class, 'subjectCoverage']);
    Route::get('grade-distribution',  [PrincipalReportController::class, 'gradeDistribution']);
    Route::get('export',              [PrincipalReportController::class, 'export']);
});

Route::get('student-accounts/bulk-upload/template', [StudentBulkController::class, 'template']);
Route::post('student-accounts/bulk-upload/preview', [StudentBulkController::class, 'preview']);
Route::post('student-accounts/bulk-upload/commit',  [StudentBulkController::class, 'commit']);
    });

    // ── Teacher only ──────────────────────────────────────────
    Route::prefix('teacher')->middleware(['role:teacher', 'maintenance'])->group(function () {

        // Own profile & password
        Route::get ('/me',              [TeacherMeController::class, 'show']);
        Route::put ('/me',              [TeacherMeController::class, 'update']);
        Route::post('/change-password', [TeacherMeController::class, 'changePassword']);
        Route::get   ('/security/sessions',        [TeacherMeController::class, 'sessions']);
        Route::delete('/security/sessions/others', [TeacherMeController::class, 'endOtherSessions']);
        Route::delete('/security/sessions/{tokenId}', [TeacherMeController::class, 'endSession']);
        Route::get   ('/security/login-history',   [TeacherMeController::class, 'loginHistory']);

        // Students
        Route::get   ('/students',                      [TeacherStudentController::class, 'allStudents']);
        Route::get   ('/my-students',                   [TeacherStudentController::class, 'myStudents']);
        Route::post  ('/students/{studentId}/assign',   [TeacherStudentController::class, 'assign']);
        Route::delete('/students/{studentId}/unassign', [TeacherStudentController::class, 'unassign']);

        // Subjects — teacher's assigned subjects + student enrollment
        Route::get   ('/subjects',                                   [SubjectController::class, 'mySubjects']);
        Route::get   ('/subjects/{id}/students',                     [SubjectController::class, 'enrolledStudents']);
        Route::get   ('/subjects/{id}/available-students',           [SubjectController::class, 'availableStudents']);
        Route::post  ('/subjects/{id}/enroll-students',              [SubjectController::class, 'enrollStudents']);
        Route::delete('/subjects/{id}/unenroll-student/{studentId}', [SubjectController::class, 'unenrollStudent']);

        // Activities (now also filterable by subject_id query param)
        Route::get   ('/activities',                          [ActivityController::class, 'index']);
        Route::post  ('/activities',                          [ActivityController::class, 'store']);
        Route::get   ('/activities/{activity}',               [ActivityController::class, 'show']);
        Route::put   ('/activities/{activity}',               [ActivityController::class, 'update']);
        Route::patch ('/activities/{activity}/toggle-status', [ActivityController::class, 'toggleStatus']);
        Route::post  ('/activities/{activity}/close',         [ActivityController::class, 'close']);
        Route::delete('/activities/{activity}',               [ActivityController::class, 'destroy']);

        // Lessons (now also filterable by subject_id query param)
        Route::get   ('/lessons',                  [LessonController::class, 'index']);
        Route::post  ('/lessons',                  [LessonController::class, 'store']);
        Route::get   ('/lessons/{lesson}',         [LessonController::class, 'show']);
        Route::match(['put', 'patch', 'post'], '/lessons/{lesson}', [LessonController::class, 'update']);
        Route::patch ('/lessons/{lesson}/publish', [LessonController::class, 'publish']);
        Route::delete('/lessons/{lesson}',         [LessonController::class, 'destroy']);

        // NEW — per-student view/download log, feeds the activity table
        // in the Lesson Details modal.
        Route::get('/lessons/{lesson}/activity', [TeacherLessonActivityController::class, 'index']);

        // Notifications
        Route::get ('/notifications',          [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        // Activity submissions & grading
        Route::get ('/activities/{activity}/submissions',   [SubmissionController::class, 'index']);
        Route::get ('/submissions/{submission}',            [SubmissionController::class, 'show']);
        Route::get ('/submissions/{submission}/answers/{answer}/photo', [SubmissionController::class, 'photo']);
        Route::post('/submissions/{submission}/grade',      [SubmissionController::class, 'grade']);

        // Media (attachments for quiz questions, etc.)
        Route::post('/media/upload', [MediaController::class, 'upload'])->middleware('throttle:uploads');

    Route::prefix('reports')->group(function () {
    Route::get('subjects',                [TeacherreportController::class, 'subjects']);
    Route::get('activities',              [TeacherreportController::class, 'activities']);
    Route::get('task-report',              [TeacherreportController::class, 'taskReport']);
    Route::get('student-lesson-activity', [TeacherreportController::class, 'studentLessonActivity']);
    Route::get('weekly-activity',         [TeacherreportController::class, 'weeklyActivity']);
    Route::get('subject-report',          [TeacherreportController::class, 'subjectReport']);
    Route::get('student-report',          [TeacherreportController::class, 'studentReport']);
    Route::get('export',                  [TeacherreportController::class, 'export']);
});

    });

    Route::middleware('role:admin,teacher')->group(function () {
        Route::get ('2fa/status',        [TwoFactorController::class, 'status']);
        Route::post('2fa/enable',        [TwoFactorController::class, 'enable']);
        Route::post('2fa/confirm-setup', [TwoFactorController::class, 'confirmSetup']);
        Route::post('2fa/disable',       [TwoFactorController::class, 'disable']);

        // Configurable mini-game & curriculum content across all grades (updateable without rebuilding APK)
        Route::get    ('game-content',           [GameContentController::class, 'index']);
        Route::get    ('game-content/{gameKey}', [GameContentController::class, 'show']);
        Route::put    ('game-content/{gameKey}', [GameContentController::class, 'update']);
        Route::delete ('game-content/{gameKey}', [GameContentController::class, 'destroy']);
    });

});

// Signed lesson file URLs must stay outside auth:sanctum. The short-lived
// signature authorizes the file request itself.
Route::middleware('signed:relative')->group(function () {
    Route::get('/lessons/{lesson}/signed-preview', [StudentLessonController::class, 'signedPreview'])
        ->name('lessons.signed-preview');
    Route::get('/lessons/{lesson}/signed-download', [StudentLessonController::class, 'signedDownload'])
        ->name('lessons.signed-download');
});
