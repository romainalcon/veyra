<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\RegistrationGate;
use App\Domain\System\Site\PageMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The root URL: signed-in users go to their dashboard. Guests see the public
 * landing page when the instance enables it (VEYRA_LANDING_PAGE), and the
 * login page otherwise, so self-hosted instances do not advertise Veyra to
 * their visitors (ADR-028).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, RegistrationGate $registration): RedirectResponse|Response
    {
        if ($request->user() !== null) {
            return to_route('dashboard');
        }

        if (! config('veyra.landing_page')) {
            return to_route('login');
        }

        return Inertia::render('Welcome', [
            'canRegister' => $registration->isPublic(),
            'sourceUrl' => config('veyra.source_url'),
        ])->withViewData(['meta' => $this->meta()]);
    }

    /**
     * @return array{title: string, description: string, url: string, image: string, image_alt: string, locale: string}
     */
    private function meta(): array
    {
        return PageMeta::make(
            __('Uptime monitoring you can host yourself'),
            __('Veyra watches your websites, APIs, servers and scheduled jobs, confirms real outages, alerts your team and keeps your users informed with public status pages.'),
            url('/'),
        );
    }
}
