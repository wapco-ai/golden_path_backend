<?php

namespace App\Repositories\Admin;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SignedUserRepositoryInterface
{
    /**
     * Signed-up end users (NOT admins).
     */
    public function paginate(int $page, int $pageSize, ?string $search = null, ?string $startDate = null, ?string $endDate = null): LengthAwarePaginator;

    public function findOrFail(int $id, bool $forUpdate = false): User;

    public function save(User $user): User;
}
