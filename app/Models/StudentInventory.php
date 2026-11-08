<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentInventory extends Model
{
    protected $table = 'student_inventory';

    protected $fillable = [
        'student_id',
        'shop_item_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function shopItem()
    {
        return $this->belongsTo(ShopItem::class);
    }
}
