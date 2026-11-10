<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GameContent extends Model
{
    protected $fillable = [
        'game_key',
        'grade',
        'yunit',
        'aralin',
        'title',
        'content',
        'updated_by',
    ];

    protected $casts = [
        'grade'   => 'integer',
        'yunit'   => 'integer',
        'aralin'  => 'integer',
        'content' => 'array',
    ];
}

