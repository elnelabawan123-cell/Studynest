<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminSetting extends Model
{
    protected $table    = 'admin_settings';
    protected $fillable = ['key', 'value', 'type', 'description'];

    // ── Read ──────────────────────────────────────────────────────────────────

    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();
        if ($row === null) return $default;
        return static::cast($row->value, $row->type ?? 'string');
    }

    public static function allAsMap(): array
    {
        return static::all()
            ->mapWithKeys(fn($row) => [
                $row->key => static::cast($row->value, $row->type ?? 'string'),
            ])
            ->toArray();
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    public static function set(string $key, mixed $value): void
    {
        $stored = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        static::where('key', $key)->update(['value' => $stored]);
    }

    // ── Cast ──────────────────────────────────────────────────────────────────

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => match ($value) {
                'true',  '1', true  => true,
                'false', '0', false => false,
                default             => (bool) $value,
            },
            'json'  => is_string($value) ? json_decode($value, true) : $value,
            default => $value,
        };
    }
}