<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit Log
 *
 * Records important system actions related to security, data integrity,
 * and accountability — especially changes that need a traceable paper trail.
 * Purpose : Track who changed what, when, and why.
 * Examples:
 *   - Admin changed a teacher's role
 *   - User password was changed
 *   - Student record was deleted
 *   - Admin granted or revoked permissions
 *   - Settings were modified by an administrator
 *
 * @see Activitylog  for general user-behaviour monitoring
 */
class Auditlog extends Model
{
    // Migration created the table as 'audit_logs'
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id', 'user_role', 'action', 'table_name',
        'record_id', 'old_value', 'new_value', 'description', 'ip_address',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
    ];

    // ── Common action constants ────────────────────────────────────────────────
    // Use these when calling Auditlog::record() so action values stay consistent.

    // Data changes
    const ACTION_CREATE          = 'CREATE';
    const ACTION_UPDATE          = 'UPDATE';
    const ACTION_DELETE          = 'DELETE';
    const ACTION_RESTORE         = 'RESTORE';

    // Security & access
    const ACTION_ROLE_CHANGE     = 'ROLE_CHANGE';
    const ACTION_PASSWORD_CHANGE = 'PASSWORD_CHANGE';
    const ACTION_PERMISSION_GRANT= 'PERMISSION_GRANT';
    const ACTION_PERMISSION_REVOKE='PERMISSION_REVOKE';
    const ACTION_ACCOUNT_LOCK    = 'ACCOUNT_LOCK';
    const ACTION_ACCOUNT_UNLOCK  = 'ACCOUNT_UNLOCK';

    // System settings
    const ACTION_SETTINGS_UPDATE = 'SETTINGS_UPDATE';
    const ACTION_MAINTENANCE_ON  = 'MAINTENANCE_ON';
    const ACTION_MAINTENANCE_OFF = 'MAINTENANCE_OFF';

    // ── Relationship ───────────────────────────────────────────────────────────
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault(['name' => 'System']);
    }

    // ── Static recorder ───────────────────────────────────────────────────────
    /**
     * Record an auditable system event.
     *
     * @param  string       $action      One of the ACTION_* constants above
     * @param  string       $tableName   DB table affected, e.g. 'users', 'students', 'settings'
     * @param  int|null     $recordId    Primary key of the affected row (null for system-wide)
     * @param  mixed        $oldValue    State before the change (array or scalar)
     * @param  mixed        $newValue    State after the change  (array or scalar)
     * @param  string       $description Human-readable sentence, e.g. "Admin changed role of Juan Dela Cruz from teacher to admin"
     */
    public static function record(
        string  $action,
        string  $tableName,
        ?int    $recordId,
        mixed   $oldValue,
        mixed   $newValue,
        string  $description,
        ?int    $userId   = null,
        ?string $userRole = null,
        ?string $ip       = null,
    ): static {
        return static::create([
            'user_id'     => $userId   ?? auth()->id(),
            'user_role'   => $userRole ?? auth()->user()?->role,
            'action'      => $action,
            'table_name'  => $tableName,
            'record_id'   => $recordId,
            'old_value'   => is_array($oldValue) ? $oldValue : ['value' => $oldValue],
            'new_value'   => is_array($newValue) ? $newValue : ['value' => $newValue],
            'description' => $description,
            'ip_address'  => $ip ?? request()->ip(),
        ]);
    }
}