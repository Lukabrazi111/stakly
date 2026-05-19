<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams chat link-preview images out of the private `local` disk.
 *
 * Unlike `MessageController::attachment`, these images aren't scoped to
 * a specific match — a single link-preview file is shared across every
 * message that references the same OG image URL (dedup via sha256(url)
 * filename). The auth gate ("must be logged in") is the only access
 * control, which is acceptable because link images are by definition
 * public previews of public web pages. The auth gate exists at all so
 * an unauth visitor can't enumerate cached previews to learn that
 * Stakly users have been linking $URL.
 *
 * `?hash` and `?ext` are part of the URL — the filename on disk encodes
 * both, and the route param carries them verbatim so we don't need a
 * registry table. Filenames are caller-supplied indirectly (the
 * fetcher writes them); the controller hard-rejects anything that
 * doesn't pass the `[a-f0-9]{64}\.(jpg|png|webp|gif)` shape so
 * `../../etc/passwd` and friends can't slip through.
 *
 * Cache-Control mirrors `MessageController::attachment` — `private,
 * immutable, 1y`. Filenames are hash-addressed so any change to the
 * image bytes lands at a different path.
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
