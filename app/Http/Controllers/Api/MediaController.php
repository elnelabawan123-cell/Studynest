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
                'mimes:' . implode(',', [...MediaUploadLimits::IMAGE_EXTS, 'pdf', ...self::PRESENTATION_EXTS, ...MediaUploadLimits::VIDEO_EXTS]),
                'max:102400',
            ],
        ], [
            'file.max'   => 'The uploaded file exceeds the 100 MB limit (photos: 5 MB, videos: 50 MB / 5 minutes, PDF & PowerPoint: 100 MB).',
            'file.mimes' => 'Allowed formats: JPG, PNG, WEBP, PDF, PPT, PPTX, MP4, MOV, WEBM, M4V.',
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        $type = match (true) {
            $ext === 'pdf'                                            => 'pdf',
            in_array($ext, MediaUploadLimits::VIDEO_EXTS, true)       => 'video',
            in_array($ext, self::PRESENTATION_EXTS, true)             => 'presentation',
            default                                                   => 'image',
        };

        if ($error = MediaUploadLimits::validateUploadedFile($file)) {
            return response()->json(['message' => $error], 422);
        }

        $duration = $type === 'video'
            ? MediaUploadLimits::videoDurationSeconds((string) $file->getRealPath())
            : null;

        $path = $file->store('question-attachments', 'public');

        return response()->json([
            'path'     => $path,
            'url'      => StorageFileUrl::forPath($path),
            'type'     => $type,
            'duration' => $duration,
        ], 201);
    }

}