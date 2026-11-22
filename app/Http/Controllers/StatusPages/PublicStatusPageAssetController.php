<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Models\StatusPageAsset;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branding images of a published page, sessionless and cookie-free. The
 * file name carries the content hash, so responses are immutable.
 */
class PublicStatusPageAssetController extends Controller
{
    public const FILE_PATTERN = '(logo|favicon)-[a-f0-9]{16}\.(png|jpg|webp|ico)';

    public function __invoke(string $slug, string $file): Response
    {
        return $this->serve(PublicStatusPageController::published($slug), $file);
    }

    public function serve(StatusPage $statusPage, string $file): Response
    {
        abort_unless($statusPage->is_published && preg_match('/^'.self::FILE_PATTERN.'$/', $file) === 1, 404);

        $asset = $statusPage->assets()->where('kind', strstr($file, '-', true))->first();

        abort_if($asset === null || $asset->fileName() !== $file, 404);

        return self::imageResponse($asset, 'public, max-age=31536000, immutable');
    }

    public static function imageResponse(StatusPageAsset $asset, string $cacheControl): Response
    {
        return response($asset->content, 200, [
            'Content-Type' => $asset->mime,
            'Content-Length' => (string) $asset->size,
            'Cache-Control' => $cacheControl,
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => 'inline; filename="'.$asset->fileName().'"',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
