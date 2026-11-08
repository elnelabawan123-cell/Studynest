<?php

namespace Tests\Unit;

use App\Support\StorageFileUrl;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class StorageFileUrlTest extends TestCase
{
    public function test_local_storage_urls_keep_the_existing_behavior(): void
    {
        config(['filesystems.disks.public.driver' => 'local']);

        $disk = Mockery::mock();
        $disk->shouldReceive('url')
            ->once()
            ->with('lessons/example.pdf')
            ->andReturn('http://localhost/storage/lessons/example.pdf');
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);

        $this->assertSame(
            'http://localhost/storage/lessons/example.pdf',
            StorageFileUrl::forPath('lessons/example.pdf')
        );
    }

    public function test_gcs_storage_urls_are_signed_and_expiring(): void
    {
        config([
            'filesystems.disks.public.driver' => 'gcs',
            'filesystems.disks.public.signed_url_minutes' => 60,
        ]);

        $disk = Mockery::mock();
        $disk->shouldReceive('temporaryUrl')
            ->once()
            ->with('lessons/example.pdf', Mockery::type(\DateTimeInterface::class))
            ->andReturn('https://storage.googleapis.com/signed-file');
        Storage::shouldReceive('disk')->once()->with('public')->andReturn($disk);

        $this->assertSame(
            'https://storage.googleapis.com/signed-file',
            StorageFileUrl::forPath('lessons/example.pdf')
        );
    }

    public function test_empty_paths_do_not_generate_urls(): void
    {
        $this->assertNull(StorageFileUrl::forPath(null));
        $this->assertNull(StorageFileUrl::forPath(''));
    }
}
