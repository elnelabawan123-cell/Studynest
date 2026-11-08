<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Activity Log
 *
 * Records the normal, day-to-day activities performed by users in the system.
 * Purpose : Monitor what users are doing across the platform.
 * Examples:
 *   - Teacher uploaded a lesson
 *   - Student answered a quiz
 *   - Admin added a subject
 *   - Teacher created an assignment
 *   - Student submitted a module
 *
 * @see Auditlog  for security-sensitive and data-accountability events
 */
class Activitylog extends Model
{
    // Migration created the table as 'activity_logs'
    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id', 'user_role', 'action', 'module', 'description', 'ip_address',
    ];

    // ── Common action constants ────────────────────────────────────────────────
    // Use these when calling Activitylog::record() so action values stay consistent.

    // Teacher actions
    const ACTION_UPLOAD_LESSON     = 'UPLOAD_LESSON';
    const ACTION_CREATE_QUIZ       = 'CREATE_QUIZ';
    const ACTION_CREATE_ASSIGNMENT = 'CREATE_ASSIGNMENT';
    const ACTION_GRADE_SUBMISSION  = 'GRADE_SUBMISSION';
    const ACTION_POST_ANNOUNCEMENT = 'POST_ANNOUNCEMENT';

    // Student actions
    const ACTION_ANSWER_QUIZ       = 'ANSWER_QUIZ';
    const ACTION_SUBMIT_ASSIGNMENT = 'SUBMIT_ASSIGNMENT';
    const ACTION_VIEW_LESSON       = 'VIEW_LESSON';
    const ACTION_COMPLETE_MODULE   = 'COMPLETE_MODULE';

    // Admin actions
    const ACTION_ADD_SUBJECT       = 'ADD_SUBJECT';
    const ACTION_ENROLL_STUDENT    = 'ENROLL_STUDENT';
    const ACTION_ASSIGN_SECTION    = 'ASSIGN_SECTION';

    // Session
    const ACTION_LOGIN             = 'LOGIN';
    const ACTION_LOGOUT            = 'LOGOUT';

    // ── Relationship ───────────────────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(['name' => 'System']);
    }

    // ── Static recorder ───────────────────────────────────────────────────────
    /**
     * Record a user activity.
     *
     * @param  string       $action      One of the ACTION_* constants above
     * @param  string       $module      Feature area, e.g. 'Lessons', 'Quizzes', 'Subjects'
     * @param  string       $description Human-readable sentence, e.g. "Teacher uploaded Lesson 3"
     */
    public static function record(
        string  $action,
        string  $module,
        string  $description,
        ?int    $userId   = null,
        ?string $userRole = null,
        ?string $ip       = null,
    ): ?static {
        if (!AdminSetting::get('activity_logs_enabled', true)) {
            return null;
        }

        return static::create([
            'user_id'     => $userId   ?? auth()->id(),
            'user_role'   => $userRole ?? auth()->user()?->role,
            'action'      => $action,
            'module'      => $module,
            'description' => $description,
            'ip_address'  => $ip ?? request()->ip(),
        ]);
    }
}