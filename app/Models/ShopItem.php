<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopItem extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'emoji',
        'price',
        'category',
        'effect_config',
        'is_active',
    ];

    protected $casts = [
        'price' => 'integer',
        'effect_config' => 'array',
        'is_active' => 'boolean',
    ];
}
