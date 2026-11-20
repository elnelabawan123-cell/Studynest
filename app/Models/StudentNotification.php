<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentNotification extends Model
{
    protected $fillable = [
        'student_user_id',
        'notification_key',
        'type',
        'title',
        'body',
        'subject_name',
        'target_type',
        'target_id',
        'event_at',
        'read_at',
        'dismissed_at',
    ];

    protected $casts = [
        'student_user_id' => 'integer',
        'target_id'       => 'integer',
        'event_at'        => 'datetime',
        'read_at'         => 'datetime',
        'dismissed_at'    => 'datetime',
    ];

    public function studentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at')->whereNull('read_at');
    }

    public function toApiArray(): array
    {
        $timestamp = $this->event_at ?? $this->created_at;

        return [
            'id'               => $this->id,
            'notification_key' => $this->notification_key,
            'type'             => $this->type,
            'category'         => $this->type,
            'title'            => $this->title,
            'body'             => $this->body,
            'subject'          => $this->subject_name,
            'target_type'      => $this->target_type,
            'target_id'        => $this->target_id,
            'is_read'          => $this->read_at !== null,
            'read_at'          => $this->read_at?->toIso8601String(),
            'created_at'       => $timestamp?->toIso8601String(),
        ];
    }
}
