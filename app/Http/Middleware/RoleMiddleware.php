<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles)
    {
        // 🔐 No token / not logged in
        if (! $request->user()) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // 🔐 Logged in but wrong role
        if (! in_array($request->user()->role, $roles)) {
            return response()->json([
                'message' => 'Forbidden: insufficient permission'
            ], 403);
        }

        return $next($request);
    }
}
