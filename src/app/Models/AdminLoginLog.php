<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminLoginLog extends Model
{
    protected $table = 'admin_login_logs';

    protected $fillable = [
        'user_id', 'identifier', 'ip', 'user_agent', 'success', 'failure_reason',
    ];

    protected $casts = [
        'success' => 'boolean',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
