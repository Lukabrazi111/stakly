<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams chat link-preview images from the private `local` disk.
 *
 * Auth gate is the only access control — link images are by definition
 * public previews of public web pages, deduped across messages by
 * sha256(url) filename. The gate exists so an unauth visitor can't
 * enumerate cached previews to learn what Stakly users have been linking.
 *
 * Filenames are caller-supplied indirectly (the fetcher writes them); the
 * `[a-f0-9]{64}\.(jpg|png|webp|gif)` regex hard-rejects anything else so
 * `../../etc/passwd` and friends can't slip through.
 */
class LinkImageController extends Controller
{
    private const FILENAME_PATTERN = '/^[a-f0-9]{64}\.(jpg|png|webp|gif)$/';

    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public function show(Request $request, string $filename): BinaryFileResponse
    {
        abort_unless(preg_match(self::FILENAME_PATTERN, $filename, $m) === 1, 404);

        $disk = Storage::disk('local');
        $relativePath = 'link-images/'.$filename;

        abort_unless($disk->exists($relativePath), 404);

        $mime = self::MIME_BY_EXTENSION[$m[1]];

        $response = response()->file($disk->path($relativePath), [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);

        $response->headers->set('Cache-Control', 'private, max-age=31536000, immutable');

        return $response;
    }
}
