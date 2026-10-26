<?php

namespace App\Http\Controllers;

use finfo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PublicUploadController extends Controller
{
    private const ALLOWED_DIRECTORIES = [
        'restaurant-menu',
        'restaurant-menu-categories',
        'restaurant-branding',
    ];

    private const ALLOWED_IMAGES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private const STREAM_CHUNK_BYTES = 8192;

    public function show(string $directory, int $restaurantId, string $filename): StreamedResponse
    {
        abort_unless(in_array($directory, self::ALLOWED_DIRECTORIES, true), Response::HTTP_NOT_FOUND);

        $filename = basename(rawurldecode($filename));

        abort_unless(
            $restaurantId > 0
                && preg_match('/\A[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)\z/i', $filename) === 1,
            Response::HTTP_NOT_FOUND,
        );

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $disk = Storage::disk('public');
        abort_unless($disk instanceof FilesystemAdapter, Response::HTTP_NOT_FOUND);

        $absolutePath = $this->resolvedPublicImagePath($disk, $directory, $restaurantId, $filename);
        abort_unless(is_string($absolutePath), Response::HTTP_NOT_FOUND);

        $mimeType = self::ALLOWED_IMAGES[$extension];
        abort_unless($this->isAllowedDetectedMime($this->detectedMimeType($absolutePath)), Response::HTTP_NOT_FOUND);

        $fileSize = @filesize($absolutePath);
        abort_unless(is_int($fileSize) && $fileSize >= 0, Response::HTTP_NOT_FOUND);

        return response()->stream(function () use ($absolutePath, $fileSize): void {
            $stream = @fopen($absolutePath, 'rb');

            if (! is_resource($stream)) {
                return;
            }

            try {
                $bytesRemaining = $fileSize;

                while ($bytesRemaining > 0 && ! feof($stream)) {
                    $chunk = fread($stream, min(self::STREAM_CHUNK_BYTES, $bytesRemaining));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $bytesRemaining -= strlen($chunk);

                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $fileSize,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function resolvedPublicImagePath(
        FilesystemAdapter $disk,
        string $directory,
        int $restaurantId,
        string $filename,
    ): ?string {
        try {
            $relativeDirectory = $directory.'/'.$restaurantId;
            $relativePath = $relativeDirectory.'/'.$filename;

            if (! $disk->exists($relativePath)) {
                return null;
            }

            $directoryRoot = realpath($disk->path($relativeDirectory));
            $realFile = realpath($disk->path($relativePath));
        } catch (Throwable) {
            return null;
        }

        if ($directoryRoot === false || $realFile === false || ! is_file($realFile) || ! is_readable($realFile)) {
            return null;
        }

        $normalizedRoot = $this->normalizePath($directoryRoot);
        $normalizedFile = $this->normalizePath($realFile);

        if ($normalizedFile !== $normalizedRoot && ! str_starts_with($normalizedFile, $normalizedRoot.'/')) {
            return null;
        }

        return $realFile;
    }

    private function normalizePath(string $path): string
    {
        return strtolower(str_replace('\\', '/', $path));
    }

    private function detectedMimeType(string $absolutePath): ?string
    {
        if (! class_exists(finfo::class)) {
            return null;
        }

        try {
            $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
        } catch (Throwable) {
            return null;
        }

        return is_string($detectedMime) && $detectedMime !== '' ? $detectedMime : null;
    }

    private function isAllowedDetectedMime(?string $detectedMime): bool
    {
        if ($detectedMime === null || $detectedMime === 'application/octet-stream') {
            return true;
        }

        return in_array($detectedMime, self::ALLOWED_IMAGES, true);
    }
}
