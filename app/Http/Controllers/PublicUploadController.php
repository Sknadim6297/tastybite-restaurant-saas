<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicUploadController extends Controller
{
    private const ALLOWED_IMAGES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    public function show(string $directory, int $restaurantId, string $filename): StreamedResponse
    {
        abort_unless(in_array($directory, [
            'restaurant-menu',
            'restaurant-menu-categories',
            'restaurant-branding',
        ], true), Response::HTTP_NOT_FOUND);

        abort_unless(
            $restaurantId > 0
                && preg_match('/\A[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)\z/i', $filename) === 1,
            Response::HTTP_NOT_FOUND,
        );

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $path = "{$directory}/{$restaurantId}/{$filename}";
        $disk = Storage::disk('public');

        abort_unless($disk->exists($path), Response::HTTP_NOT_FOUND);

        $mimeType = $disk->mimeType($path);
        abort_unless(
            is_string($mimeType) && $mimeType === self::ALLOWED_IMAGES[$extension],
            Response::HTTP_NOT_FOUND,
        );

        $stream = $disk->readStream($path);
        abort_unless(is_resource($stream), Response::HTTP_NOT_FOUND);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
