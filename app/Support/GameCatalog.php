<?php

namespace App\Support;

class GameCatalog
{
    private static ?array $cached = null;

    /**
     * Load all built-in Grade 1-6 (2D & 3D) curriculum game entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $path = resource_path('data/game_catalog.json');
        if (!is_file($path)) {
            self::$cached = [];
            return self::$cached;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$cached = is_array($decoded) ? $decoded : [];

        return self::$cached;
    }

    /**
     * Look up a single built-in curriculum game definition by its canonical game_key.
     *
     * @return array<string, mixed>|null
     */
    public static function find(string $gameKey): ?array
    {
        foreach (self::all() as $entry) {
            if (($entry['gameKey'] ?? null) === $gameKey) {
                return $entry;
            }
        }

        return null;
    }

    public static function clearCache(): void
    {
        self::$cached = null;
    }
}

