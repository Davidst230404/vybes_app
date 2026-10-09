<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     * Ensures the authenticated user possesses the admin role or admin.manage permission.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Your account has been suspended. Please contact platform administration.',
            ], 403);
        }

        if (!$user->hasRole('admin') && !$user->hasPermission('admin.manage')) {
            return response()->json([
                'message' => 'You do not have permission to access the VYBES Admin CMS.',
            ], 403);
        }

        return $next($request);
    }
}
