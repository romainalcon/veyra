<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia middleware for public, sessionless pages (status pages). Shares
 * nothing about users or organizations: only the instance name, the page
 * language (that of the owning organization) and the AGPL source link.
 */
class HandlePublicInertiaRequests extends Middleware
{
    /**
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            'name' => config('app.name'),
            'locale' => fn (): string => app()->getLocale(),
            'system' => [
                'version' => (string) config('veyra.version'),
                'sourceUrl' => config('veyra.source_url'),
            ],
        ];
    }
}
