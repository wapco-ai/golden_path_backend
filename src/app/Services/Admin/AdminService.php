<?php

namespace App\Services\Admin;

use App\Models\AdminAuth;
use App\Models\AdminRole;
use App\Models\User;
use App\Repositories\Admin\AdminRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;


class AdminService
{
    public function __construct(private readonly AdminRepositoryInterface $repo) {}

    public function paginate(int $page, int $pageSize, ?string $search = null): LengthAwarePaginator
    {
        return $this->repo->paginate($page, $pageSize, $search);
    }

    public function getOne(int $id): User
    {
        return $this->repo->findOrFail($id);
    }

    public function create(string $firstName, string $lastName, string $username, array $roles): User
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $username = trim($username);

        return DB::transaction(function () use ($firstName, $lastName, $username, $roles) {
            // unique username check (return 409)
            if (User::query()->where('username', $username)->exists()) {
                abort(409, 'Username already exists');
            }

            $user = new User();
            $user->name = trim($firstName . ' ' . $lastName);
            $user->username = $username;
            $user->is_admin = true;
            $user->status = 'active';
            $user->is_active = true;
            $this->repo->save($user);

            $roleIds = $this->resolveRoleIdsByTitle($roles);
            $user->adminRoles()->sync($roleIds);

            // Create admin auth record (no password field in UI)
            AdminAuth::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'password_hash' => Hash::make(Str::random(24)),
                    'mfa_enabled' => false,
                    'mfa_secret' => null,
                    'last_password_change_at' => now(),
                ]
            );

            return $this->repo->findOrFail($user->id);
        });
    }

    public function updateRoles(int $id, array $roles): User
    {
        return DB::transaction(function () use ($id, $roles) {
            $user = $this->repo->findOrFail($id);
            $roleIds = $this->resolveRoleIdsByTitle($roles);
            $user->adminRoles()->sync($roleIds);
            return $this->repo->findOrFail($user->id);
        });
    }

    public function updateAdmin(int $id, array $payload): User
    {
        return DB::transaction(function () use ($id, $payload) {
            $user = $this->repo->findOrFail($id);

            // --- email update (users.email)
            if (array_key_exists('email', $payload)) {
                $user->email = trim((string)$payload['email']);
            }

            // --- password update (admin_auth.password_hash + users.password fallback)
            if (array_key_exists('password', $payload)) {
                $hashed = Hash::make((string)$payload['password']);

                // users.password (fallback in AdminAuthController)
                $user->password = $hashed;

                // admin_auth.password_hash (primary in AdminAuthController)
                AdminAuth::query()->updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'password_hash' => $hashed,
                        'last_password_change_at' => now(),
                    ]
                );
            }

            // persist changes (if any)
            $this->repo->save($user);

            // --- roles update (required in your UI)
            if (array_key_exists('roles', $payload)) {
                $roleIds = $this->resolveRoleIdsByTitle($payload['roles']);
                $user->adminRoles()->sync($roleIds);
            }

            return $this->repo->findOrFail($user->id);
        });
    }


    public function deleteAdmin(int $id): void
    {
        DB::transaction(function () use ($id) {
            $user = $this->repo->findOrFail($id);

            // 1) Remove admin roles
            $user->adminRoles()->detach();

            // 2) Remove admin auth record
            \App\Models\AdminAuth::query()->where('user_id', $user->id)->delete();

            // 3) Remove admin flag
            // $user->is_admin = false;

            // 4) ✅ Disable user so it won't appear in Users list (assuming list filters active users)
            // IMPORTANT: your DB constraint allows only: active, locked, disabled
            if (property_exists($user, 'status') || array_key_exists('status', $user->getAttributes())) {
                $user->status = 'disabled';
            }

            // Optional: if you have "is_active" boolean in your project
            if (property_exists($user, 'is_active') || array_key_exists('is_active', $user->getAttributes())) {
                $user->is_active = false;
            }

            $this->repo->save($user);
        });
    }

    /**
     * @return int[]
     */
    private function resolveRoleIdsByTitle(array $titles): array
    {
        $titles = array_values(array_filter(array_map(fn($x) => is_string($x) ? trim($x) : '', $titles)));
        $titles = array_values(array_unique(array_filter($titles, fn($x) => $x !== '')));

        if (count($titles) === 0) {
            abort(422, 'roles is required');
        }

        $rows = AdminRole::query()
            ->whereIn('title', $titles)
            ->get(['id', 'title']);

        $found = $rows->pluck('title')->all();
        $missing = array_values(array_diff($titles, $found));
        if (count($missing) > 0) {
            abort(422, 'Invalid roles: ' . implode(', ', $missing));
        }

        return $rows->pluck('id')->values()->all();
    }
}
