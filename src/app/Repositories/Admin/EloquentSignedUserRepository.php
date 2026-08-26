<?php

namespace App\Repositories\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentSignedUserRepository implements SignedUserRepositoryInterface
{
    public function paginate(int $page, int $pageSize, ?string $search = null): LengthAwarePaginator
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $search = is_string($search) ? trim($search) : null;

        $q = User::query()
            ->where('is_admin', false);

        if ($search !== null && $search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhere('mobile', 'ilike', "%{$search}%")
                    ->orWhere('username', 'ilike', "%{$search}%");
            });
        }

        return $q
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $pageSize,
                columns: ['*'],
                pageName: 'page',
                page: $page
            );
    }

    public function findOrFail(int $id): User
    {
        return User::query()
            ->where('is_admin', false)
            ->findOrFail($id);
    }

    public function save(User $user): User
    {
        $user->save();
        return $user;
    }
}
