<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacheractivitylog extends Model
{
    use HasFactory;

    // Explicit table name — Laravel would otherwise guess "teacheractivitylogs"
    protected $table = 'teacher_activity_logs';

    protected $fillable = [
        'teacher_id', 'subject_id',
        'reference_id', 'reference_type',
        'activity_type', 'title', 'description', 'status',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}