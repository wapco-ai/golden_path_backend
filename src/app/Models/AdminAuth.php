<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuth extends Model
{
    protected $table = 'admin_auth';

    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'password_hash',
        'mfa_enabled',
        'mfa_secret',
        'last_password_change_at',
    ];

    protected $casts = [
        'mfa_enabled' => 'boolean',
        'last_password_change_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public $timestamps = true;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
