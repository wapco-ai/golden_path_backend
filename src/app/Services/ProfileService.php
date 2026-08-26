<?php

namespace App\Services;

use App\Models\User;

class ProfileService
{
    public function isProfileCompleted(User $user): bool
    {
        $fullNameOk  = (string)($user->name ?? '') !== '';
        $emailOrNid  = ((string)($user->email ?? '') !== '') || ((string)($user->national_id ?? '') !== '');
        $birthOk     = !empty($user->birth_date);

        return $fullNameOk && $emailOrNid && $birthOk;
    }
}
