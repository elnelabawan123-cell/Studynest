<?php

namespace App\Http\Middleware;

use App\Models\AdminSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Block non-admin users when maintenance mode is on.
     * Admins (role === 'admin') always pass through.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maintenanceOn = AdminSetting::get('maintenance_mode', false);

        if ($maintenanceOn) {
            $user = $request->user();

            // Unauthenticated or non-admin → block
            if (!$user || $user->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'The system is currently under maintenance. Please try again later.',
                    'maintenance' => true,
                ], 503);
            }
        }

        return $next($request);
    }
}