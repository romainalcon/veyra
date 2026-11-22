<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $status = $request->session()->get('status');
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;

        return Inertia::render('settings/Security', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'twoFactor' => [
                'enabled' => $user->hasEnabledTwoFactorAuthentication(),
                // The QR code and setup key are only shown until the first code is confirmed.
                'pending' => $pending,
                'qrCodeSvg' => $pending ? $user->twoFactorQrCodeSvg() : null,
                'setupKey' => $pending ? decrypt((string) $user->two_factor_secret) : null,
                // Recovery codes are shown right after confirmation or regeneration only.
                'recoveryCodes' => $user->hasEnabledTwoFactorAuthentication() && in_array($status, [Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED, Fortify::RECOVERY_CODES_GENERATED], true)
                    ? $user->recoveryCodes()
                    : null,
                'requiredBy' => $user->organizations()->where('require_two_factor', true)->orderBy('name')->pluck('name')->all(),
            ],
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
