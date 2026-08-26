<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    protected $table = 'contents';

    protected $fillable = [
        'poi_id',
        'lang',
        'title',
        'body',
        'media',
        'status',
        'created_at', // اگر created_at را خودت ست می‌کنی
    ];

    protected $casts = [
        'media' => 'array',
    ];

    // اگر timestamps پیش‌فرض لاراول فعال است
    public $timestamps = true;
}
