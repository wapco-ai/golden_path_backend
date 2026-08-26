<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Destination extends Model
{
    protected $table = 'destinations';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'x',
        'y',
        'floor',
        'source',
        'source_id',
        'tags',
        'address',
        'metadata',
    ];

    protected $casts = [
        'x' => 'float',
        'y' => 'float',
        'floor' => 'integer',
        'tags' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
