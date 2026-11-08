<?php
 
namespace App\Models;
 
use App\Support\StorageFileUrl;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\FormatsTitleCaseForDisplay;

class QuizQuestion extends Model
{
    use HasFactory, FormatsTitleCaseForDisplay;

    protected function titleCaseDisplayAttributes(): array
    {
        return ['question'];
    }
 
    protected $fillable = [
        'activity_id',
        'order',
        'question',
        'answer_type',     // Multiple Choice | Text | Photo
        'checking_mode',   // Auto | Manual
        'choices',
        'correct_answer',
        'points',
        'attachment_path', // supporting material: image or PDF
        'attachment_type', // 'image' | 'pdf'
    ];
 
    protected $casts = [
        'choices' => 'array',
        'order'   => 'integer',
        'points'  => 'integer',
    ];
 
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(SubmissionAnswer::class);
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        return StorageFileUrl::forPath($this->attachment_path);
    }
}