<?php

namespace App\Support;

use App\Models\User;

final class RequestActivity
{
    public const ACTOR = 'activity.actor';
    public const ENTITY_ID = 'activity.entity_id';
    public const DELETED_ROWS = 'activity.deleted_rows';
    public const LOGOUT_TOKEN_MATCHED = 'activity.logout_token_matched';

    public static function identify(User $user): void
    {
        // Write to the original HTTP request, including when a FormRequest was injected.
        request()->attributes->set(self::ACTOR, $user);
    }
}
