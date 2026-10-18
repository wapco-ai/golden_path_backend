<?php

namespace App\Repositories\Admin;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentSignedUserRepository implements SignedUserRepositoryInterface
{
    public function paginate(int $page, int $pageSize, ?string $search = null, ?string $startDate = null, ?string $endDate = null): LengthAwarePaginator
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $search = is_string($search) ? trim($search) : null;

        $q = User::query()
            ->where('is_admin', false);

        // users.created_at is a timestamp WITHOUT timezone containing UTC.
        // Interpret calendar dates as Tehran days, then bind naive UTC bounds.
        if ($startDate !== null) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate, 'Asia/Tehran')->utc();
            $q->where('created_at', '>=', $start->format('Y-m-d H:i:s'));
        }
        if ($endDate !== null) {
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'Asia/Tehran')->addDay()->startOfDay()->utc();
            $q->where('created_at', '<', $end->format('Y-m-d H:i:s'));
        }

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
            ->orderByDesc('id')
            ->paginate(
                perPage: $pageSize,
                columns: ['*'],
                pageName: 'page',
                page: $page
            );
    }

    public function findOrFail(int $id, bool $forUpdate = false): User
    {
        return User::query()
            ->where('is_admin', false)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->findOrFail($id);
    }

    public function save(User $user): User
    {
        $user->save();
        return $user;
    }
}
