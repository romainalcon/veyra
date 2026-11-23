<?php

namespace App\Http\Controllers\Site;

use App\Domain\Identity\Services\RegistrationGate;
use App\Domain\System\Site\Changelog;
use App\Domain\System\Site\PageMeta;
use App\Domain\System\Site\Roadmap;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public roadmap, served with the landing page only (ADR-028, ADR-049): a
 * 404 on instances that do not advertise Veyra.
 */
class RoadmapController extends Controller
{
    public function __invoke(Roadmap $roadmap, Changelog $changelog, RegistrationGate $registration): Response
    {
        abort_unless(config('veyra.landing_page'), 404);

        $latest = $changelog->latest();

        return Inertia::render('site/Roadmap', [
            'milestones' => $roadmap->milestones(),
            'latestRelease' => $latest === null ? null : [
                'version' => $latest['version'],
                'date' => $latest['date'],
                'href' => route('changelog').'#'.$latest['anchor'],
            ],
            'canRegister' => $registration->isPublic(),
        ])->withViewData(['meta' => PageMeta::make(
            __('Roadmap'),
            __('What is coming next in Veyra, in the order we are working on it.'),
            route('roadmap'),
        )]);
    }
}
