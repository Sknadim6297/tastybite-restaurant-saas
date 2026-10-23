<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicUploadServingTest extends TestCase
{
    public function test_public_upload_route_streams_supported_images_without_a_storage_symlink(): void
    {
        Storage::fake('public');

        foreach ([
            'restaurant-menu',
            'restaurant-menu-categories',
            'restaurant-branding',
        ] as $directory) {
            $filename = 'image.png';
            $upload = UploadedFile::fake()->image($filename, 32, 32);
            $path = $upload->storeAs("{$directory}/17", $filename, 'public');
            $url = route('public.uploads.show', [
                'directory' => $directory,
                'restaurantId' => 17,
                'filename' => $filename,
            ]);

            $response = $this->get($url);

            $response->assertOk()
                ->assertHeader('Content-Type', 'image/png')
                ->assertHeader('Content-Disposition', 'inline; filename="'.$filename.'"')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'max-age=86400, public');
            $this->assertSame(Storage::disk('public')->get($path), $response->streamedContent());
        }
    }

    public function test_public_upload_route_rejects_unapproved_files_and_traversal(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('restaurant-menu/17/document.pdf', 'not an image');

        $this->get('/uploads/private/17/image.png')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/document.pdf')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/%2e%2e%2Fdocument.png')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/missing.png')->assertNotFound();
    }
}
