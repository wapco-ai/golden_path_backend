<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuidancePoint extends Model
{
    use SoftDeletes;

    protected $table = 'guidance_points';

    protected $fillable = [
        'floor', 'area_id', 'title', 'description', 'x', 'y',
        'view_direction', 'azimuth_deg', 'coverage_radius_m', 'sort_order',
        'primary_image_url', 'is_active', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'floor' => 'integer',
        'area_id' => 'integer',
        'x' => 'float',
        'y' => 'float',
        'azimuth_deg' => 'float',
        'coverage_radius_m' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'updated_by' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function images()
    {
        return $this->hasMany(GuidancePointImage::class, 'point_id')->orderBy('sort_order');
    }
}
