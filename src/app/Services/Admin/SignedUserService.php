<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Repositories\Admin\SignedUserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SignedUserService
{
    public function __construct(private readonly SignedUserRepositoryInterface $repo) {}

    public function paginate(int $page, int $pageSize, ?string $search = null): LengthAwarePaginator
    {
        return $this->repo->paginate($page, $pageSize, $search);
    }

    public function getOne(int $id): User
    {
        return $this->repo->findOrFail($id);
    }

    /**
     * UI sends: active|inactive
     * DB allows: active|locked|disabled
     */
    public function updateStatus(int $id, string $status): User
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['active', 'inactive'], true)) {
            abort(422, 'Invalid status');
        }

        $user = $this->repo->findOrFail($id);

        if ($status === 'active') {
            $user->status = 'active';     // ✅ allowed
            $user->is_active = true;
        } else { // inactive
            $user->status = 'disabled';   // ✅ map inactive -> disabled
            $user->is_active = false;
        }

        return $this->repo->save($user);
    }
}
