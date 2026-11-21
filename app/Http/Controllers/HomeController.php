<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Services\RegistrationGate;
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
        ]);
    }
}
