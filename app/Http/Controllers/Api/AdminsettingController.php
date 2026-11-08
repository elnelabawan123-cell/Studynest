<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdminSetting;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
 
class AdminsettingController extends Controller
{
    // Valid keys + validation rules — single source of truth
    private const SCHEMA = [
        'auto_section_assignment' => ['type' => 'boolean', 'rule' => 'boolean'],
        'teacher_registration'    => ['type' => 'boolean', 'rule' => 'boolean'],
        'student_self_enrollment' => ['type' => 'boolean', 'rule' => 'boolean'],
        'admin_multi_login'       => ['type' => 'boolean', 'rule' => 'boolean'],
        'activity_logs_enabled'   => ['type' => 'boolean', 'rule' => 'boolean'],
        'email_notifications'     => ['type' => 'boolean', 'rule' => 'boolean'],
        'maintenance_mode'        => ['type' => 'boolean', 'rule' => 'boolean'],
    ];
 
    // ── GET /api/admin/settings ───────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $settings = AdminSetting::allAsMap();
        unset($settings['school_year'], $settings['grading_system']);

        return response()->json([
            'success'  => true,
            'settings' => $settings,
        ]);
    }
 
    // ── GET /api/admin/settings/keys ─────────────────────────────────────────
    public function availableKeys(): JsonResponse
    {
        $keys = array_values(array_map(
            fn($key, $meta) => ['key' => $key, 'type' => $meta['type']],
            array_keys(self::SCHEMA),
            self::SCHEMA
        ));
 
        return response()->json([
            'success' => true,
            'keys'    => $keys,
        ]);
    }
 
    // ── GET /api/admin/settings/{key} ────────────────────────────────────────
    public function show(string $key): JsonResponse
    {
        if (!array_key_exists($key, self::SCHEMA)) {
            return response()->json([
                'success' => false,
                'message' => "Unknown setting key: '{$key}'.",
            ], 404);
        }
 
        return response()->json([
            'success' => true,
            'key'     => $key,
            'value'   => AdminSetting::get($key),
            'type'    => self::SCHEMA[$key]['type'],
        ]);
    }
 
    // ── PUT /api/admin/settings ───────────────────────────────────────────────
    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (self::SCHEMA as $key => $meta) {
            $rules[$key] = 'sometimes|' . $meta['rule'];
        }
 
        $data    = $request->validate($rules);
        $changed = $this->applyChanges($data);
 
        return response()->json([
            'success' => true,
            'message' => 'Settings saved successfully.',
            'changed' => array_keys($changed),
        ]);
    }
 
    // ── PUT /api/admin/settings/{key} ────────────────────────────────────────
    public function updateSingle(Request $request, string $key): JsonResponse
    {
        if (!array_key_exists($key, self::SCHEMA)) {
            return response()->json([
                'success' => false,
                'message' => "Unknown setting key: '{$key}'.",
            ], 404);
        }
 
        $data    = $request->validate(['value' => 'required|' . self::SCHEMA[$key]['rule']]);
        $changed = $this->applyChanges([$key => $data['value']]);
 
        return response()->json([
            'success' => true,
            'message' => "Setting '{$key}' updated.",
            'changed' => array_keys($changed),
            'value'   => AdminSetting::get($key),
        ]);
    }
 
    // ── PUT /api/admin/settings/maintenance-mode ──────────────────────────────
    // Dedicated endpoint — takes effect immediately, not batched with Save Changes
    public function toggleMaintenance(Request $request): JsonResponse
    {
        $request->validate(['enabled' => 'required|boolean']);
        $enabled = $request->boolean('enabled');
 
        $old = AdminSetting::get('maintenance_mode', false);
        AdminSetting::set('maintenance_mode', $enabled);
 
        $label = $enabled ? 'enabled' : 'disabled';
 
        ActivityLog::record('toggled', 'Settings', "Admin {$label} Maintenance Mode.");
 
        AuditLog::record(
            'updated', 'admin_settings', null,
            ['maintenance_mode' => $old],
            ['maintenance_mode' => $enabled],
            "Maintenance Mode {$label} by admin."
        );
 
        return response()->json([
            'success' => true,
            'message' => "Maintenance mode {$label}.",
            'enabled' => $enabled,
        ]);
    }
 
    // ── Internal: persist changes + write logs ────────────────────────────────
    private function applyChanges(array $data): array
    {
        $changed = [];
 
        foreach ($data as $key => $newVal) {
            $oldVal = AdminSetting::get($key);
            if ($oldVal !== $newVal) {
                $changed[$key] = ['old' => $oldVal, 'new' => $newVal];
                AdminSetting::set($key, $newVal);
            }
        }
 
        if (!empty($changed)) {
            $summary = implode(', ', array_map(
                fn($k, $c) => "{$k}: {$this->fmt($c['old'])} → {$this->fmt($c['new'])}",
                array_keys($changed),
                $changed
            ));
 
            ActivityLog::record('updated', 'Settings', "Admin updated settings: {$summary}.");
 
            foreach ($changed as $key => $c) {
                AuditLog::record(
                    'updated', 'admin_settings', null,
                    [$key => $c['old']],
                    [$key => $c['new']],
                    "Setting '{$key}' changed from {$this->fmt($c['old'])} to {$this->fmt($c['new'])}."
                );
            }
        }
 
        return $changed;
    }
 
    private function fmt(mixed $v): string
    {
        if (is_bool($v))  return $v ? 'true' : 'false';
        if (is_null($v))  return 'null';
        return (string) $v;
    }
}