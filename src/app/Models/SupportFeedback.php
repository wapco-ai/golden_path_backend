<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportFeedback extends Model
{
    protected $table = 'support_feedbacks';

    protected $fillable = [
        'user_id',
        'subject',
        'message',
        'status',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
