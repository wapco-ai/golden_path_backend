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
            'admin/admins' => ['مدیریت ادمین ها'],
            'admin/users' => ['مدیریت کاربران ثبت نام کرده', 'مدیریت کاربران'],
            'admin/user-logs' => ['مدیریت لاگ های مسیریابی'],
            'admin/categories' => ['مدیریت دسته بندی'],
            'admin/subcategories' => ['مدیریت دسته بندی'],
            'admin/pages' => ['مدیریت صفحات'],
            'admin/support-feedbacks' => ['مدیریت بازخورد ها'],
            'admin/feedbacks' => ['مدیریت بازخورد ها'],
            'admin/comments' => ['مدیریت دیدگاه ها'],
            'admin/dashboard' => ['مدیریت گزارشات'],
            'admin/connectors' => ['مدیریت نقشه'],
            'admin/van' => ['مدیریت نقشه'],
            'admin/temp-block-areas' => ['مدیریت نقشه'],
            'admin/guidance-points' => ['مدیریت نقشه'],
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
        $titles = self::titlesFor($request->route()->uri(), $request->method());
        if (!$titles || !$request->user() || !AdminAccess::hasAny($request->user(), $titles)) {
            return response()->json(['error' => 'FORBIDDEN', 'message' => 'Access denied'], 403);
        }
        return $next($request);
    }
}
