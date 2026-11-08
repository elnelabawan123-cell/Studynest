<?php

namespace App\Support;

final class MediaUploadLimits
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_VIDEO_BYTES = 50 * 1024 * 1024;
    public const MAX_VIDEO_SECONDS = 300;

    public static function videoDurationSeconds(string $path): ?float
    {
        if (! class_exists(\getID3::class)) {
            return null;
        }

        $getID3 = new \getID3();
        $info   = $getID3->analyze($path);

        return isset($info['playtime_seconds']) ? (float) $info['playtime_seconds'] : null;
    }
}
