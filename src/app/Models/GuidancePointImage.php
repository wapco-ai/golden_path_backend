<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuidancePointImage extends Model
{
    protected $table = 'guidance_point_images';

    protected $fillable = ['point_id', 'image_url', 'image_key', 'sort_order'];

    protected $casts = [
        'point_id' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
