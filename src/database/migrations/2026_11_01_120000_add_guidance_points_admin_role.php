<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('admin_roles')->updateOrInsert(
                ['code' => 'manage_guidance_points'], ['title' => 'مدیریت نقاط راهنما']
            );
            DB::table('admin_permissions')->updateOrInsert(
                ['code' => 'manage_guidance_points'], ['title' => 'مدیریت نقاط راهنما']
            );
            $roleId = DB::table('admin_roles')->where('code', 'manage_guidance_points')->value('id');
            $permissionId = DB::table('admin_permissions')->where('code', 'manage_guidance_points')->value('id');
            DB::table('admin_role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);

            // Preserve the guidance access map administrators already had.
            // This runs only during migration; future map grants are independent.
            $mapRoles = DB::table('admin_roles')->where('title', 'مدیریت نقشه')->pluck('id');
            $users = DB::table('admin_user_roles')->whereIn('role_id', $mapRoles)->distinct()->pluck('user_id');
            foreach ($users as $userId) {
                DB::table('admin_user_roles')->updateOrInsert(['user_id' => $userId, 'role_id' => $roleId]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $roleId = DB::table('admin_roles')->where('code', 'manage_guidance_points')->value('id');
            $permissionId = DB::table('admin_permissions')->where('code', 'manage_guidance_points')->value('id');
            if ($roleId !== null) {
                DB::table('admin_user_roles')->where('role_id', $roleId)->delete();
                DB::table('admin_role_permissions')->where('role_id', $roleId)->delete();
                DB::table('admin_roles')->where('id', $roleId)->delete();
            }
            if ($permissionId !== null) {
                DB::table('admin_role_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('admin_permissions')->where('id', $permissionId)->delete();
            }
        });
    }
};
