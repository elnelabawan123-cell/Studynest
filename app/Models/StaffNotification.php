<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent notification item for teacher and admin dashboard bells.
 */
class StaffNotification extends Model
{
    protected $fillable = [
        'user_id',
        'notification_key',
        'type',
        'title',
        'message',
        'target_type',
        'target_id',
        'event_at',
        'read_at',
        'dismissed_at',
    ];

    protected $casts = [
        'user_id'      => 'integer',
        'target_id'    => 'integer',
        'event_at'     => 'datetime',
        'read_at'      => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'title'            => $this->title,
            'message'          => $this->message,
            'type'             => $this->type,
            'target_type'      => $this->target_type,
            'target_id'        => $this->target_id,
            'is_read'          => $this->read_at !== null,
            'read_at'          => $this->read_at?->toIso8601String(),
            'created_at'       => $timestamp?->toIso8601String(),
        ];
    }
}
