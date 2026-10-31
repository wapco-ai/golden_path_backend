<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\AdminRole;

/** Server-side source of truth for the roles exposed in the admin editor. */
class AdminAccess
{
    public const SUPER_TITLE = 'ادمین کل داشبورد';

    public static function hasAny(User $user, array $titles): bool
    {
        // The dashboard role is an independent checkbox, not an override for
        // section roles that the administrator has explicitly removed.
        return $user->adminRoles()->whereIn('title', $titles)->exists();
    }

    /** Administrators may manage only accounts and roles within their own authority. */
    public static function mayManage(User $actor, ?User $target, array $newTitles = []): bool
    {
        $owned = $actor->adminRoles()->pluck('admin_roles.id')->all();
        $existing = $target ? $target->adminRoles()->pluck('admin_roles.id')->all() : [];
        // Match AdminService's normalization before checking grant authority.
        $newTitles = array_values(array_unique(array_filter(array_map(
            fn ($title) => is_string($title) ? trim($title) : '',
            $newTitles
        ), fn ($title) => $title !== '')));
        $requested = AdminRole::query()->whereIn('title', $newTitles)->pluck('id')->all();
        return count(array_diff(array_merge($existing, $requested), $owned)) === 0;
    }
}
