<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Shared upload size and video-duration limits for teacher lesson and
 * activity media uploads.
 */
final class MediaUploadLimits
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    public const MAX_VIDEO_BYTES = 50 * 1024 * 1024;
    public const MAX_VIDEO_SECONDS = 300;
    public const MAX_DOCUMENT_BYTES = 100 * 1024 * 1024;

    public const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
    public const VIDEO_EXTS = ['mp4', 'mov', 'avi', 'webm', 'm4v'];

    /**
     * Validate a single uploaded media or document file against per-type size
     * and video duration limits. Returns a user-facing error message if a limit
     * is exceeded, or null when valid.
     */
    public static function validateUploadedFile(
        UploadedFile $file,
        array $videoExts = self::VIDEO_EXTS,
    ): ?string {
        $ext = strtolower($file->getClientOriginalExtension());
        $name = $file->getClientOriginalName();
        $size = (int) $file->getSize();

        if (in_array($ext, self::IMAGE_EXTS, true)) {
            if ($size > self::MAX_IMAGE_BYTES) {
                return "The file \"{$name}\" exceeds the 5 MB limit (photos must be 5MB or smaller).";
            }

            return null;
        }

        if (in_array($ext, $videoExts, true)) {
            if ($size > self::MAX_VIDEO_BYTES) {
                return "The file \"{$name}\" exceeds the 50 MB limit (videos must be 50MB or smaller).";
            }

            $duration = self::videoDurationSeconds((string) $file->getRealPath());
            if ($duration === null) {
                return 'Could not read that video\'s length — please try a different file.';
            }
            if ($duration > self::MAX_VIDEO_SECONDS) {
                return "The video \"{$name}\" exceeds the 5-minute limit (videos must be 5 minutes or shorter; this one is "
                    . gmdate('i:s', (int) round($duration)) . ' long).';
            }

            return null;
        }

        if ($size > self::MAX_DOCUMENT_BYTES) {
            return "The file \"{$name}\" exceeds the 100 MB limit (documents and slides must be 100MB or smaller).";
        }

        return null;
    }

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
