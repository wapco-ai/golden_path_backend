<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLocationShare extends Model
{
    protected $table = 'user_location_shares';

    protected $fillable = [
        'sender_user_id',
        'recipient_user_id',
        'floor',
        'accuracy_m',
        'source',
        'expires_at',
        'viewed_at',
        'revoked_at',
    ];

    protected $casts = [
        'sender_user_id' => 'integer',
        'recipient_user_id' => 'integer',
        'floor' => 'integer',
        'accuracy_m' => 'float',
        'expires_at' => 'datetime',
        'viewed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
