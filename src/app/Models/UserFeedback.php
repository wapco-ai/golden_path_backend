<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFeedback extends Model
{
    protected $table = 'user_feedbacks';

    protected $fillable = [
        'user_id',
        'target_type',
        'target_id',
        'lang',
        'rating',
        'title',
        'body',
        'status',
        'admin_note',
        'route_log_id',
        'attrs',
        'approved_at',
        'approved_by',
    ];

    protected $casts = [
        'attrs'       => 'array',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
