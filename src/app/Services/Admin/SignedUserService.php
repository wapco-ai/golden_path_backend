<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Repositories\Admin\SignedUserRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SignedUserService
{
    public function __construct(private readonly SignedUserRepositoryInterface $repo) {}

    public function paginate(int $page, int $pageSize, ?string $search = null, ?string $startDate = null, ?string $endDate = null): LengthAwarePaginator
    {
        return $this->repo->paginate($page, $pageSize, $search, $startDate, $endDate);
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

        return DB::transaction(function () use ($id, $status) {
            // Recheck after acquiring the row lock: simultaneous activation
            // requests must observe the first transition and keep its timestamp.
            $user = $this->repo->findOrFail($id, true);
            $recordActivation = false;
            if ($status === 'active') {
                $recordActivation = $user->status !== 'active' || !$user->is_active;
                $user->status = 'active';
                $user->is_active = true;
            } else {
                $user->status = 'disabled';
                $user->is_active = false;
            }
            // OTP locks and login counters belong to authentication, not this
            // manual activation history, and intentionally remain unchanged.
            $user = $this->repo->save($user);
            if ($recordActivation) {
                // Write the DB instant directly, preserving timezone and
                // precision instead of passing through Eloquent's naive format.
                DB::table('users')->where('id', $id)->update([
                    'last_manual_activation_at' => DB::raw('clock_timestamp()'),
                ]);
                $user = $this->repo->findOrFail($id);
            }
            return $user;
        });
    }
}
