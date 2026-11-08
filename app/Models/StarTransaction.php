<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StarTransaction extends Model
{
    protected $fillable = [
        'student_id',
        'amount',
        'balance_after',
        'type',
        'reference',
        'description',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
