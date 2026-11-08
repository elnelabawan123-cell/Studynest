<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MediaUploadLimits;
use App\Support\StorageFileUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Small, reusable upload endpoint. Used by the quiz-question builder to
 * attach supporting materials (image/video/PDF/PowerPoint) to a question or to an
 * activity's instructions, and to attach an image to an individual
 * Multiple Choice option — both just need "give me a URL back", not a
 * dedicated form-request per feature.
 *
 * POST /api/teacher/media/upload  (multipart/form-data, field: file)
 * → { path, url, type: 'image'|'video'|'pdf'|'presentation', duration?: float }
 *
 * Requires: composer require james-heinrich/getid3
 * (used only to read a video's duration server-side — never trust a
 * client-reported duration for validation).
 */
class MediaController extends Controller
{
    private const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'webp'];
    private const VIDEO_EXTS = ['mp4', 'mov', 'webm', 'm4v'];
    private const PRESENTATION_EXTS = ['ppt', 'pptx'];

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:' . implode(',', [...self::IMAGE_EXTS, 'pdf', ...self::PRESENTATION_EXTS, ...self::VIDEO_EXTS]),
                'max:102400',
            ],
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        $type = match (true) {
            $ext === 'pdf'                          => 'pdf',
            in_array($ext, self::VIDEO_EXTS, true)  => 'video',
            in_array($ext, self::PRESENTATION_EXTS, true) => 'presentation',
            default                                  => 'image',
        };

        if ($type === 'image' && $file->getSize() > MediaUploadLimits::MAX_IMAGE_BYTES) {
            return response()->json([
                'message' => 'Photos must be 5MB or smaller.',
            ], 422);
        }

        $duration = null;

        if ($type === 'video') {
            if ($file->getSize() > MediaUploadLimits::MAX_VIDEO_BYTES) {
                return response()->json([
                    'message' => 'Videos must be 50MB or smaller.',
                ], 422);
            }

            $duration = MediaUploadLimits::videoDurationSeconds($file->getRealPath());

            if ($duration === null) {
                return response()->json([
                    'message' => 'Could not read that video\'s length — please try a different file.',
                ], 422);
            }

            if ($duration > MediaUploadLimits::MAX_VIDEO_SECONDS) {
                return response()->json([
                    'message' => 'Videos must be 5 minutes or shorter. This one is ' .
                        gmdate('i:s', (int) round($duration)) . ' long.',
                ], 422);
            }
        }

        $path = $file->store('question-attachments', 'public');

        return response()->json([
            'path'     => $path,
            'url'      => StorageFileUrl::forPath($path),
            'type'     => $type,
            'duration' => $duration,
        ], 201);
    }

}