<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    // â”€â”€ GET /api/admin/activity-logs â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
    // Query params: per_page, page, search, user_role, action, date_from, date_to
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $query = ActivityLog::with('user:id,name')
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('action', 'like', "%{$search}%")
                  ->orWhere('module', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQuery) use ($search) {
                      $userQuery->where('name', 'like', "%{$search}%");
                  });
            });
        }

        if ($role = $request->query('user_role')) {
            $query->where('user_role', $role);
        }

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action'      => ['required', 'string', 'max:100'],
            'module'      => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:500'],
        ]);

        $log = ActivityLog::record(
            $data['action'],
            $data['module'] ?? 'General',
            $data['description'],
            $request->user()?->id,
            $request->user()?->role,
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'data'    => $log,
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $total = ActivityLog::count();
        $today = ActivityLog::whereDate('created_at', today())->count();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_logs' => $total,
                'today_logs' => $today,
            ],
        ]);
    }
}