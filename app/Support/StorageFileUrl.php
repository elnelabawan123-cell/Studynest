<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class StorageFileUrl
{
    public static function forPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $disk = Storage::disk('public');

        if (config('filesystems.disks.public.driver') === 'gcs') {
            $minutes = max(1, (int) config('filesystems.disks.public.signed_url_minutes', 60));

            return $disk->temporaryUrl($path, now()->addMinutes($minutes));
        }

        return $disk->url($path);
    }
}
