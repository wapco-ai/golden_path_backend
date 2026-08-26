<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRefreshToken extends Model
{
    protected $table = 'user_refresh_tokens';

    protected $fillable = [
        'user_id', 'token', 'user_agent', 'ip', 'expires_at', 'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
