<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentGameReward extends Model
{
    protected $fillable = [
        'student_id',
        'game_key',
        'title',
        'subject',
        'emoji',
        'stars',
        'status',
        'completed_at',
        'attempts',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
