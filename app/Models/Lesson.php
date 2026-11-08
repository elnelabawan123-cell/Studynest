<?php

namespace App\Models;

use App\Support\StorageFileUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class Lesson extends Model
{
    use HasFactory, FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['title', 'description'];
    }

    protected $fillable = [
        'teacher_id',
        'subject_id',
        'title',
        'description',
        'type',          // Slides | PPT | Video | PDF
        'status',        // scheduled | published  (draft no longer exists)
        'scheduled_at',  // when a "scheduled" lesson should auto-publish
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
        'views',
        'downloads',
        'progress',
        'published_at',
    ];

    protected $casts = [
        'file_size'    => 'integer',
        'views'        => 'integer',
        'downloads'    => 'integer',
        'progress'     => 'integer',
        'scheduled_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /* ─── Relations ───────────────────────────────────────────── */

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'teacher_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** NEW — per-student view/download log, backs the teacher's activity table. */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(LessonActivityLog::class);
    }

    /* ─── Scopes ──────────────────────────────────────────────── */

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    /** Scheduled lessons whose publish date/time has arrived. */
    public function scopeDueForPublish($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now());
    }

    public function scopeForTeacher($query, int $teacherId)
    {
        return $query->where('teacher_id', $teacherId);
    }

    /* ─── Helpers ─────────────────────────────────────────────── */

    /**
     * Auto-detect lesson type from a stored filename.
     */
    public static function detectType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match (true) {
            in_array($ext, ['ppt', 'pptx', 'key']) => 'PPT',
            $ext === 'pdf'                         => 'PDF',
            in_array($ext, ['mp4', 'mov', 'avi'])  => 'Video',
            in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) => 'Image',
            default                                => 'Slides',
        };
    }

    /**
     * Public URL of the stored file (null if no file).
     * NOTE: still used for teacher-side (React) previews/downloads, which
     * aren't behind the same CORS/auth constraints as the Flutter student
     * app — see StudentLessonController::stream() for why students use a
     * different, authenticated route instead of this raw URL.
     */
    public function getFileUrlAttribute(): ?string
    {
        return StorageFileUrl::forPath($this->file_path);
    }

    /** True once scheduled_at is in the past but status hasn't flipped yet. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'scheduled'
            && $this->scheduled_at !== null
            && $this->scheduled_at->isPast();
    }
}
