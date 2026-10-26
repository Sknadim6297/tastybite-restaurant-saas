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
            foreach ([
                'image.jpg' => 'image/jpeg',
                'image.jpeg' => 'image/jpeg',
                'image.png' => 'image/png',
                'image.webp' => 'image/webp',
            ] as $filename => $contentType) {
                $upload = UploadedFile::fake()->image($filename, 32, 32);
                $path = $upload->storeAs("{$directory}/17", $filename, 'public');
                $url = route('public.uploads.show', [
                    'directory' => $directory,
                    'restaurantId' => 17,
                    'filename' => $filename,
                ]);

                $response = $this->get($url);

                $response->assertOk()
                    ->assertHeader('Content-Type', $contentType)
                    ->assertHeader('Content-Length', (string) strlen(Storage::disk('public')->get($path)))
                    ->assertHeader('Content-Disposition', 'inline; filename="'.$filename.'"')
                    ->assertHeader('X-Content-Type-Options', 'nosniff')
                    ->assertHeader('Cache-Control', 'max-age=86400, public');
                $this->assertSame(Storage::disk('public')->get($path), $response->streamedContent());
            }
        }
    }

    public function test_pos_demo_menu_image_url_streams_webp_from_the_public_disk(): void
    {
        Storage::fake('public');

        $filename = 'pos-demo-chicken.webp';
        $upload = UploadedFile::fake()->image($filename, 48, 48);
        $path = $upload->storeAs('restaurant-menu/2', $filename, 'public');

        $response = $this->get('/uploads/restaurant-menu/2/pos-demo-chicken.webp');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $this->assertSame(Storage::disk('public')->get($path), $response->streamedContent());
    }

    public function test_existing_local_pos_demo_chicken_webp_is_streamed_from_storage(): void
    {
        $path = 'restaurant-menu/2/pos-demo-chicken.webp';

        if (! Storage::disk('public')->exists($path)) {
            $this->markTestSkipped('POS demo image is not present on the public disk.');
        }

        $contents = Storage::disk('public')->get($path);
        $response = $this->get('/uploads/restaurant-menu/2/pos-demo-chicken.webp');

        $response->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Content-Length', (string) strlen($contents));
        $this->assertSame($contents, $response->streamedContent());
        $this->assertStringStartsWith('RIFF', $contents);
        $this->assertStringContainsString('WEBP', substr($contents, 0, 16));
    }

    public function test_public_upload_route_rejects_unapproved_files_and_traversal(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('restaurant-menu/17/document.pdf', 'not an image');
        Storage::disk('public')->put('restaurant-menu/17/script.txt', 'not an image');
        Storage::disk('public')->put('restaurant-menu/17/payload.png', '<?php echo "no";');

        $this->get('/uploads/private/17/image.png')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/document.pdf')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/script.txt')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/payload.png')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/%2e%2e%2Fdocument.png')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/../document.png')->assertNotFound();
        $this->get('/uploads/restaurant-menu/17/missing.png')->assertNotFound();
        $this->get('/uploads/restaurant-menu/0/image.png')->assertNotFound();
    }
}
