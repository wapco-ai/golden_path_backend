<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $table = 'pages';

    protected $fillable = [
        'type',
        'title',
        'description',
        'phones',
        'emails',
        'address',
    ];

    protected $casts = [
        'phones' => 'array',
        'emails' => 'array',
    ];
}
