<?php

namespace App\Http\Controllers\Site;

use App\Domain\Identity\Services\RegistrationGate;
use App\Domain\System\Site\Changelog;
use App\Domain\System\Site\PageMeta;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public changelog, served with the landing page only (ADR-028, ADR-049):
 * a 404 on instances that do not advertise Veyra. Release notes are written
 * in English, whatever the interface language.
 */
class ChangelogController extends Controller
{
    public function __invoke(Changelog $changelog, RegistrationGate $registration): Response
    {
        abort_unless(config('veyra.landing_page'), 404);

        return Inertia::render('site/Changelog', [
            'releases' => $changelog->releases(),
            'canRegister' => $registration->isPublic(),
        ])->withViewData(['meta' => PageMeta::make(
            __('Changelog'),
            __('Every Veyra release: new features, changes and fixes.'),
            route('changelog'),
        )]);
    }
}
