<?php

namespace App\Http\Middleware;

use App\Services\Admin\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protect every route in the admin groups; unclassified routes fail closed. */
class AdminSectionAccess
{
    public static function titlesFor(string $uri, string $method = 'GET'): ?array
    {
        $uri = trim(preg_replace('#^api/v1/#', '', $uri), '/');
        if ($method === 'GET' && ($uri === 'admin/categories' || str_starts_with($uri, 'admin/categories/'))) {
            return ['مدیریت دسته بندی', 'مدیریت اطلاعات فرهنگی'];
        }
        $sections = [
            'admin/navigation-settings' => [AdminAccess::SUPER_TITLE],
            'admin/admins' => ['مدیریت ادمین ها'],
            'admin/users' => ['مدیریت کاربران ثبت نام کرده', 'مدیریت کاربران'],
            'admin/user-logs' => ['مدیریت لاگ های مسیریابی'],
            'admin/activity-logs' => ['مدیریت گزارشات'],
            'admin/categories' => ['مدیریت دسته بندی'],
            'admin/subcategories' => ['مدیریت دسته بندی'],
            'admin/pages' => ['مدیریت صفحات'],
            'admin/support-feedbacks' => ['مدیریت بازخورد ها'],
            'admin/feedbacks' => ['مدیریت بازخورد ها'],
            'admin/comments' => ['مدیریت دیدگاه ها'],
            'admin/dashboard' => [AdminAccess::SUPER_TITLE, 'مدیریت گزارشات'],
            'admin/connectors' => ['مدیریت نقشه'],
            'admin/same-floor-stairs' => ['مدیریت نقشه'],
            'admin/van' => ['مدیریت نقشه'],
            'admin/temp-block-areas' => ['مدیریت نقشه'],
            'admin/guidance-points' => [AdminAccess::GUIDANCE_TITLE],
            'areas' => ['مدیریت نقشه'],
            'doors' => ['مدیریت نقشه'],
            'cultural-items' => ['مدیریت اطلاعات فرهنگی'],
        ];
        foreach ($sections as $prefix => $titles) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) return $titles;
        }
        return null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $uri = $request->route()->uri();
        $titles = self::titlesFor($uri, $request->method());
        $user = $request->user();
        $allowed = $user && $titles && ($uri === 'api/v1/admin/navigation-settings'
            ? AdminAccess::canManageNavigationSettings($user)
            : AdminAccess::hasAny($user, $titles));
        if (!$allowed) {
            return response()->json(['error' => 'FORBIDDEN', 'message' => 'Access denied'], 403);
        }
        return $next($request);
    }
}
