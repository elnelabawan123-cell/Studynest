<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditlog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    // ── GET /api/admin/audit-logs ──────────────────────────────────────────────
    // Purpose : Track who changed what, when, and why.
    //           Covers security events, role changes, data edits/deletes,
    //           and any action requiring accountability.
    //
    // Query params: per_page, page, search, table_name, user_role, action, date_from, date_to
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $query = Auditlog::with('user:id,name')
            ->orderByDesc('created_at');

        // Full-text search across description, action, and table name
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('action',      'like', "%{$search}%")
                  ->orWhere('table_name',  'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by the database table that was affected (e.g. users, students)
        if ($table = $request->query('table_name')) {
            $query->where('table_name', $table);
        }

        // Filter by the role of the user who performed the action
        if ($role = $request->query('user_role')) {
            $query->where('user_role', $role);
        }

        // Filter by action type (e.g. UPDATE, DELETE, ROLE_CHANGE, PASSWORD_CHANGE)
        if ($action = $request->query('action')) {
            $query->whereRaw('LOWER(action) = ?', [strtolower($action)]);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $perPage = (int) $request->query('per_page', 20);
        $logs    = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}