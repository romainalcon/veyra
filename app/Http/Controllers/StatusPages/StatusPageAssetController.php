<?php

namespace App\Http\Controllers\StatusPages;

use App\Domain\Organizations\Models\Organization;
use App\Domain\StatusPages\Actions\RemoveStatusPageAsset;
use App\Domain\StatusPages\Actions\StoreStatusPageAsset;
use App\Domain\StatusPages\Enums\AssetKind;
use App\Domain\StatusPages\Models\StatusPage;
use App\Domain\StatusPages\Support\ImageInspector;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logo and favicon management for page managers (ADR-036).
 */
class StatusPageAssetController extends Controller
{
    /**
     * Preview for managers, also while the page is unpublished.
     */
    public function show(Organization $organization, StatusPage $statusPage, string $kind): Response
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $asset = $statusPage->assets()->where('kind', AssetKind::from($kind))->firstOrFail();

        return PublicStatusPageAssetController::imageResponse($asset, 'private, no-cache');
    }

    public function store(Request $request, Organization $organization, StatusPage $statusPage, string $kind, StoreStatusPageAsset $storeAsset): RedirectResponse
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $assetKind = AssetKind::from($kind);

        $request->validate([
            'file' => ['required', 'file', 'max:'.intdiv($assetKind->maxBytes(), 1024)],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $bytes = (string) file_get_contents($file->getRealPath());
        $image = ImageInspector::inspect($bytes, $assetKind);

        if ($image === null) {
            throw ValidationException::withMessages(['file' => $assetKind === AssetKind::Logo
                ? __('Use a PNG, JPEG or WebP image.')
                : __('Use a PNG, ICO or WebP image.')]);
        }

        if (max($image['width'], $image['height']) > $assetKind->maxDimension()) {
            throw ValidationException::withMessages(['file' => __('The image must be at most :size pixels wide and high.', ['size' => $assetKind->maxDimension()])]);
        }

        $storeAsset->handle($statusPage, $assetKind, $bytes);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image saved.')]);

        return to_route('organizations.status-pages.edit', [$organization, $statusPage]);
    }

    public function destroy(Organization $organization, StatusPage $statusPage, string $kind, RemoveStatusPageAsset $removeAsset): RedirectResponse
    {
        Gate::authorize('update', [$statusPage, $organization]);

        $removeAsset->handle($statusPage, AssetKind::from($kind));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Image removed.')]);

        return to_route('organizations.status-pages.edit', [$organization, $statusPage]);
    }
}
