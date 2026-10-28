<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\AdminRole;

/** Server-side source of truth for the roles exposed in the admin editor. */
class AdminAccess
{
    public const SUPER_TITLE = 'ادمین کل داشبورد';

    public static function isSuper(User $user): bool
    {
        return $user->adminRoles()->where(function ($q) {
            $q->where('code', 'super_dashboard_admin')->orWhere('title', self::SUPER_TITLE);
        })->exists();
    }

    public static function hasAny(User $user, array $titles): bool
    {
        return self::isSuper($user) || $user->adminRoles()->whereIn('title', $titles)->exists();
    }

    /** A non-super administrator may manage only accounts and roles within their own authority. */
    public static function mayManage(User $actor, ?User $target, array $newTitles = []): bool
    {
        if (self::isSuper($actor)) return true;
        if ($target && self::isSuper($target)) return false;

        $owned = $actor->adminRoles()->pluck('admin_roles.id')->all();
        $existing = $target ? $target->adminRoles()->pluck('admin_roles.id')->all() : [];
        $requested = AdminRole::query()->whereIn('title', $newTitles)->pluck('id')->all();
        return !in_array(self::SUPER_TITLE, $newTitles, true)
            && count(array_diff(array_merge($existing, $requested), $owned)) === 0;
    }
}
