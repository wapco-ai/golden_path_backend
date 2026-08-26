<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $table = 'areas';
    protected $primaryKey = 'id';

    public const CREATED_AT = null;
    public const UPDATED_AT = 'updated_at';

    protected $casts = [
        'is_closed'         => 'boolean',
        'weight_open_space' => 'float',
        'attrs'             => 'array',
        'floor'             => 'integer',
    ];

    protected $fillable = [
        'area_type',
        'floor',
        'allowed_gender',
        'is_closed',
        'weight_open_space',
        'attrs',
        // geom با SQL ست می‌شود
    ];
}
