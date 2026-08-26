<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'mobile',

        // OTP (public users)
        'otp_hash',
        'otp_expires_at',
        'otp_last_sent_at',

        // Admin flags/state
        'is_admin',
        'status',
        'is_active',
        'locked_until',
        'failed_login_attempts',
        'last_login_at',
        'last_login_ip',

        // keep for legacy compatibility (admins may have password)
        'password',
        'national_id',
        'gender',
        'birth_date',
        'address',
        'preferences',
        'avatar_url',
        'referral_code',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'otp_expires_at' => 'datetime',
            'otp_last_sent_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'birth_date' => 'date',
            'address' => 'array',
            'preferences' => 'array',
        ];
    }

    public function adminAuth()
    {
        return $this->hasOne(AdminAuth::class, 'user_id', 'id');
    }

    public function adminRoles()
    {
        return $this->belongsToMany(
            AdminRole::class,
            'admin_user_roles',
            'user_id',
            'role_id'
        );
    }

    /**
     * Convenience: all permissions derived from roles.
     */
    public function adminPermissions()
    {
        return AdminPermission::query()
            ->select('admin_permissions.*')
            ->join('admin_role_permissions', 'admin_role_permissions.permission_id', '=', 'admin_permissions.id')
            ->join('admin_user_roles', 'admin_user_roles.role_id', '=', 'admin_role_permissions.role_id')
            ->where('admin_user_roles.user_id', $this->id)
            ->distinct();
    }
}
