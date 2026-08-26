<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRole extends Model
{
    protected $table = 'admin_roles';

    protected $fillable = ['code', 'title'];

    public $timestamps = false;

    public function permissions()
    {
        return $this->belongsToMany(
            AdminPermission::class,
            'admin_role_permissions',
            'role_id',
            'permission_id'
        );
    }
}
