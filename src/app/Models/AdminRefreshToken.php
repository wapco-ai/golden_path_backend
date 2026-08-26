<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRefreshToken extends Model
{
    protected $table = 'admin_refresh_tokens';

    protected $fillable = [
        'user_id', 'token', 'user_agent', 'ip', 'expires_at', 'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public $timestamps = false; // چون created_at خودکار در DB ست می‌کنیم

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
