<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Studentactivitysubmission extends Model
{
    use HasFactory;

    // Explicit table name — Laravel would otherwise guess "studentactivitysubmissions"
    protected $table = 'student_activity_submissions';

    protected $fillable = [
        'student_id', 'subject_id', 'activity_id',
        'activity_type', 'status', 'score', 'submitted_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'score'        => 'decimal:2',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}