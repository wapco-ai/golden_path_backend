<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminCan
{
    public function handle(Request $request, Closure $next, string $required): Response
    {
        $user = $request->user();

        if (!$user || empty($user->is_admin)) {
            return response()->json([
                'error' => 'UNAUTHORIZED',
                'message' => 'Admin account required'
            ], 401);
        }

        // allow: "a|b|c" or "a,b,c"
        $need = preg_split('/[|,]/', $required);
        $need = array_values(array_filter(array_map('trim', $need)));

        // super dashboard admin bypass (نقش ادمین کل داشبورد)
        if (method_exists($user, 'adminRoles') &&
            $user->adminRoles()->where('code', 'super_dashboard_admin')->exists()) {
            return $next($request);
        }

        // 1) check roles
        $roleCodes = method_exists($user, 'adminRoles')
            ? $user->adminRoles()->pluck('code')->toArray()
            : [];

        foreach ($need as $n) {
            if (in_array($n, $roleCodes, true)) {
                return $next($request);
            }
        }

        // 2) check permissions (اگر role کافی نبود)
        $permCodes = method_exists($user, 'adminPermissions')
            ? $user->adminPermissions()->pluck('code')->toArray()
            : [];

        foreach ($need as $n) {
            if (in_array($n, $permCodes, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => 'FORBIDDEN',
            'message' => 'Access denied',
            'required' => $need,
            'roles' => $roleCodes,
            'permissions' => $permCodes,
        ], 403);
    }
}
