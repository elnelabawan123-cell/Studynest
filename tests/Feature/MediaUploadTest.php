<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\MediaController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    public function test_powerpoint_attachment_upload_returns_presentation_metadata(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->create(
            'assignment-reference.pptx',
            128,
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        );
        $request = Request::create(
            '/api/teacher/media/upload',
            'POST',
            [],
            [],
            ['file' => $file],
        );

        $response = app(MediaController::class)->upload($request);
        $payload = json_decode($response->getContent(), true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('presentation', $payload['type']);
        $this->assertStringEndsWith('.pptx', $payload['path']);
        Storage::disk('public')->assertExists($payload['path']);
    }
}
